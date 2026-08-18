<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\FactoryUnit;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Models\SupplierQuotation;
use App\Modules\Procurement\Models\SupplierRfq;
use Illuminate\Support\Facades\DB;

/**
 * PR-2 — RFQ issue, quotation capture, comparison, winner → draft PO.
 */
beforeEach(function (): void {
    $this->buyer = User::query()->where('email', 'purchase@maheenlabel.test')->firstOrFail();
    $this->purchaseManager = User::query()->where('email', 'purchasemanager@maheenlabel.test')->firstOrFail();
    $this->planner = User::query()->where('email', 'planner@maheenlabel.test')->firstOrFail();
    $this->operator = User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail();
    $this->unit = FactoryUnit::query()->firstOrFail();
    $this->item = Item::query()->where('is_active', true)->firstOrFail();
    $this->suppliers = Supplier::query()->where('is_approved', true)->where('is_active', true)->orderBy('id')->get();
    $this->currencyId = (int) (DB::table('currencies')->where('is_base', true)->value('id')
        ?? DB::table('currencies')->value('id'));
});

function pr2ApprovedRequisition(object $test, float $qty = 100): PurchaseRequisition
{
    $test->actingAs($test->planner)->post('/purchase-requisitions', [
        'factory_unit_id' => $test->unit->id,
        'requested_on' => now()->toDateString(),
        'required_by' => now()->addDays(14)->toDateString(),
        'lines' => [[
            'item_id' => $test->item->id,
            'uom_id' => $test->item->base_uom_id,
            'qty' => $qty,
        ]],
    ])->assertRedirect();

    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();
    $test->actingAs($test->planner)->post("/purchase-requisitions/{$requisition->id}/transition", ['to' => 'submitted']);
    $test->actingAs($test->purchaseManager)->post("/purchase-requisitions/{$requisition->id}/transition", ['to' => 'approved']);

    return $requisition->refresh();
}

function pr2DraftRfq(object $test, ?PurchaseRequisition $pr = null, float $qty = 100): SupplierRfq
{
    $pr ??= pr2ApprovedRequisition($test, $qty);

    $test->actingAs($test->buyer)->post('/rfqs', [
        'pr_id' => $pr->id,
        'status' => 'closed',
        'number' => 'RFQ-FORGED',
        'lines' => [[
            'item_id' => $test->item->id,
            'uom_id' => $test->item->base_uom_id,
            'qty' => $qty,
        ]],
    ])->assertSessionHasNoErrors();

    return SupplierRfq::query()->latest('id')->firstOrFail();
}

function pr2IssuedRfq(object $test, ?PurchaseRequisition $pr = null, float $qty = 100): SupplierRfq
{
    $rfq = pr2DraftRfq($test, $pr, $qty);
    $test->actingAs($test->buyer)->post("/rfqs/{$rfq->id}/transition", ['to' => 'issued'])
        ->assertSessionHas('success');

    return $rfq->refresh();
}

/** @param array{rate?: float, qty?: float, lead_time_days?: int} $overrides */
function pr2Quote(object $test, SupplierRfq $rfq, Supplier $supplier, array $overrides = []): SupplierQuotation
{
    $qty = $overrides['qty'] ?? 100;
    $rate = $overrides['rate'] ?? 10;

    $test->actingAs($test->buyer)->post("/rfqs/{$rfq->id}/quotations", [
        'supplier_id' => $supplier->id,
        'currency_id' => $test->currencyId,
        'lead_time_days' => $overrides['lead_time_days'] ?? 14,
        'lines' => [[
            'item_id' => $test->item->id,
            'uom_id' => $test->item->base_uom_id,
            'qty' => $qty,
            'rate' => $rate,
        ]],
    ])->assertSessionHasNoErrors();

    return SupplierQuotation::query()->where('rfq_id', $rfq->id)->where('supplier_id', $supplier->id)->firstOrFail();
}

it('refuses RFQs to operators', function (): void {
    $this->actingAs($this->operator)->get('/rfqs')->assertForbidden();
    $this->actingAs($this->operator)->post('/rfqs', ['lines' => []])->assertForbidden();
});

it('creates a draft RFQ from an approved PR and ignores client status and number', function (): void {
    $rfq = pr2DraftRfq($this);

    expect($rfq->status)->toBe(SupplierRfq::DRAFT)
        ->and($rfq->number)->toBeNull()
        ->and((int) $rfq->created_by)->toBe((int) $this->buyer->id)
        ->and($rfq->lines()->count())->toBe(1);

    $this->actingAs($this->buyer)->get('/rfqs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Procurement/Rfqs/Index'));
});

it('refuses an RFQ against a draft requisition', function (): void {
    $this->actingAs($this->planner)->post('/purchase-requisitions', [
        'factory_unit_id' => $this->unit->id,
        'requested_on' => now()->toDateString(),
        'lines' => [[
            'item_id' => $this->item->id,
            'uom_id' => $this->item->base_uom_id,
            'qty' => 10,
        ]],
    ]);
    $draft = PurchaseRequisition::query()->latest('id')->firstOrFail();

    $this->actingAs($this->buyer)->post('/rfqs', [
        'pr_id' => $draft->id,
        'lines' => [[
            'item_id' => $this->item->id,
            'uom_id' => $this->item->base_uom_id,
            'qty' => 10,
        ]],
    ])->assertSessionHasErrors('pr_id');
});

it('issues an RFQ and allocates an RFQ number', function (): void {
    $rfq = pr2IssuedRfq($this);

    expect($rfq->status)->toBe(SupplierRfq::ISSUED)
        ->and($rfq->number)->toStartWith('RFQ');
});

it('records a supplier quotation against issued RFQ lines only', function (): void {
    $rfq = pr2IssuedRfq($this);
    $quote = pr2Quote($this, $rfq, $this->suppliers[0], ['rate' => 12.5]);

    expect((float) $quote->total)->toBe(1250.0)
        ->and($quote->is_selected)->toBeFalse()
        ->and($quote->lines()->count())->toBe(1);

    $otherItem = Item::query()->where('id', '!=', $this->item->id)->where('is_active', true)->firstOrFail();

    $this->actingAs($this->buyer)->post("/rfqs/{$rfq->id}/quotations", [
        'supplier_id' => $this->suppliers[1]->id,
        'currency_id' => $this->currencyId,
        'lines' => [[
            'item_id' => $otherItem->id,
            'uom_id' => $otherItem->base_uom_id,
            'qty' => 1,
            'rate' => 1,
        ]],
    ])->assertSessionHasErrors('lines.0.item_id');
});

it('rejects a second quotation from the same supplier', function (): void {
    $rfq = pr2IssuedRfq($this);
    pr2Quote($this, $rfq, $this->suppliers[0]);

    $this->actingAs($this->buyer)->post("/rfqs/{$rfq->id}/quotations", [
        'supplier_id' => $this->suppliers[0]->id,
        'currency_id' => $this->currencyId,
        'lines' => [[
            'item_id' => $this->item->id,
            'uom_id' => $this->item->base_uom_id,
            'qty' => 100,
            'rate' => 9,
        ]],
    ])->assertSessionHasErrors('supplier_id');
});

it('compares quotations and selects a winner below the three-quote threshold', function (): void {
    $rfq = pr2IssuedRfq($this);
    $cheap = pr2Quote($this, $rfq, $this->suppliers[0], ['rate' => 8]);
    pr2Quote($this, $rfq, $this->suppliers[1], ['rate' => 11]);

    $this->actingAs($this->buyer)->get("/rfqs/{$rfq->id}/compare")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Procurement/Rfqs/Compare')->has('quotations', 2));

    $this->actingAs($this->buyer)->post("/rfqs/{$rfq->id}/select", [
        'quotation_id' => $cheap->id,
    ])->assertSessionHasNoErrors();

    expect($cheap->refresh()->is_selected)->toBeTrue()
        ->and(SupplierQuotation::query()->where('rfq_id', $rfq->id)->where('is_selected', true)->count())->toBe(1);
});

it('requires three quotations above the value threshold unless an override is given', function (): void {
    $rfq = pr2IssuedRfq($this, qty: 100);
    $quote = pr2Quote($this, $rfq, $this->suppliers[0], ['rate' => 600]);

    $this->actingAs($this->buyer)->post("/rfqs/{$rfq->id}/select", [
        'quotation_id' => $quote->id,
    ])->assertSessionHasErrors('override_reason');

    $this->actingAs($this->buyer)->post("/rfqs/{$rfq->id}/select", [
        'quotation_id' => $quote->id,
        'override_reason' => 'Sole source for this shade.',
    ])->assertSessionHasNoErrors();

    expect($quote->refresh()->is_selected)->toBeTrue();
});

it('raises a draft PO from the winning quotation and closes the RFQ', function (): void {
    $rfq = pr2IssuedRfq($this);
    $winner = pr2Quote($this, $rfq, $this->suppliers[0], ['rate' => 9, 'lead_time_days' => 21]);
    pr2Quote($this, $rfq, $this->suppliers[1], ['rate' => 12]);

    $this->actingAs($this->buyer)->post("/rfqs/{$rfq->id}/select", ['quotation_id' => $winner->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->buyer)->post("/rfqs/{$rfq->id}/purchase-order")
        ->assertRedirect();

    $order = PurchaseOrder::query()->latest('id')->firstOrFail();
    $line = DB::table('purchase_order_lines')->where('po_id', $order->id)->first();

    expect($rfq->refresh()->status)->toBe(SupplierRfq::CLOSED)
        ->and($order->status)->toBe('draft')
        ->and((int) $order->supplier_id)->toBe((int) $this->suppliers[0]->id)
        ->and((float) $line->rate)->toBe(9.0)
        ->and((float) $line->qty)->toBe(100.0)
        ->and((float) $order->total)->toBe(900.0);
});

it('does not record quotations against a draft RFQ', function (): void {
    $rfq = pr2DraftRfq($this);

    $this->actingAs($this->buyer)->post("/rfqs/{$rfq->id}/quotations", [
        'supplier_id' => $this->suppliers[0]->id,
        'currency_id' => $this->currencyId,
        'lines' => [[
            'item_id' => $this->item->id,
            'uom_id' => $this->item->base_uom_id,
            'qty' => 100,
            'rate' => 10,
        ]],
    ])->assertSessionHas('error');
});

it('cancels a draft without numbering', function (): void {
    $rfq = pr2DraftRfq($this);

    $this->actingAs($this->buyer)->post("/rfqs/{$rfq->id}/transition", ['to' => 'cancelled'])
        ->assertSessionHas('success');

    expect($rfq->refresh()->status)->toBe(SupplierRfq::CANCELLED)
        ->and($rfq->number)->toBeNull();
});
