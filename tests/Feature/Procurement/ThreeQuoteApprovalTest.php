<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * PR-2 AC4 — above a value threshold, three supplier quotations are required **before PO
 * approval**, or a documented override reason.
 *
 * The rule was enforced only at RFQ winner selection, so it governed one route into a purchase
 * order and not the other. An order raised directly reached approval with nothing to compare
 * against: `guardApproval()` asked only who was signing, never whether anyone had shopped
 * around. A buyer could step around a procurement control by not using the RFQ screen.
 *
 * The threshold is a base-currency figure (BR-51), so a foreign order converts before it is
 * measured — the same trap this rule already fell into once on the RFQ side.
 */
beforeEach(function (): void {
    $this->md = User::query()->where('email', 'md@octapussolution.com')->firstOrFail();
    $this->manager = User::query()->where('email', 'purchasemanager@octapussolution.com')->firstOrFail();

    $this->threshold = 50_000.0;
    DB::table('settings')->updateOrInsert(
        ['key' => 'rfq_three_quote_value_threshold'],
        ['value' => (string) $this->threshold],
    );
    // Keep the value band clear of this test's concern: the MD signs everything here.
    DB::table('settings')->updateOrInsert(
        ['key' => 'po_approval_band_manager'],
        ['value' => '100000'],
    );
    cache()->flush();

    $this->base = DB::table('currencies')->where('is_base', true)->firstOrFail();
    $this->usd = DB::table('currencies')->where('code', 'USD')->firstOrFail();
    $this->usdRate = (float) DB::table('exchange_rates')
        ->where('currency_id', $this->usd->id)->orderByDesc('effective_on')->value('rate_to_base');

    $supplier = DB::table('suppliers')->firstOrFail();
    DB::table('suppliers')->where('id', $supplier->id)->update(['is_approved' => true, 'is_active' => true]);
    $this->supplierId = (int) $supplier->id;
    $item = DB::table('items')->whereNotNull('base_uom_id')->firstOrFail();

    /** A pending-approval order of a given base-currency value, optionally behind an RFQ. */
    $this->order = function (float $total, ?object $currency = null, ?float $rate = null, ?int $rfqId = null) use ($item): PurchaseOrder {
        $order = PurchaseOrder::query()->create([
            'number' => 'PO-3Q-'.substr(uniqid(), -8),
            'supplier_id' => $this->supplierId,
            'rfq_id' => $rfqId,
            'factory_unit_id' => DB::table('factory_units')->where('is_active', true)->value('id'),
            'order_date' => now()->toDateString(),
            'currency_id' => ($currency ?? $this->base)->id,
            'exchange_rate' => $rate ?? 1.0,
            'subtotal' => $total,
            'total' => $total,
            'status' => 'pending_approval',
            'created_by' => $this->manager->id,
        ]);

        DB::table('purchase_order_lines')->insert([
            'po_id' => $order->id,
            'line_no' => 1,
            'item_id' => $item->id,
            'uom_id' => $item->base_uom_id,
            'qty' => 1,
            'rate' => $total,
            'amount' => $total,
        ]);

        return $order;
    };

    /** An RFQ carrying a given number of supplier quotations. */
    $this->rfqWith = function (int $quotations): int {
        $rfqId = DB::table('supplier_rfqs')->insertGetId([
            'number' => 'RFQ-3Q-'.substr(uniqid(), -8),
            'issued_on' => now()->toDateString(),
            'status' => 'issued',
        ]);

        $suppliers = DB::table('suppliers')->limit($quotations)->pluck('id');

        foreach ($suppliers as $index => $supplierId) {
            DB::table('supplier_quotations')->insert([
                'rfq_id' => $rfqId,
                'supplier_id' => $supplierId,
                'quoted_on' => now()->toDateString(),
                'currency_id' => $this->base->id,
                'total' => 60_000 + $index,
            ]);
        }

        return $rfqId;
    };
});

it('pr2: refuses approval above the threshold with no quotations at all', function (): void {
    $order = ($this->order)(60_000.0);

    expect(fn () => app(App\Modules\Procurement\States\PurchaseOrderStateMachine::class)
        ->transition($order, 'approved'))->toThrow(TransitionDenied::class);

    expect($order->fresh()->status)->toBe('pending_approval');
})->group('pr2');

it('pr2: refuses approval above the threshold with one quotation', function (): void {
    $order = ($this->order)(60_000.0, rfqId: ($this->rfqWith)(1));

    $this->actingAs($this->md)
        ->post("/purchase-orders/{$order->id}/transition", ['to' => 'approved'])
        ->assertSessionHas('error');

    expect($order->fresh()->status)->toBe('pending_approval');
});

it('pr2: refuses approval above the threshold with two quotations', function (): void {
    $order = ($this->order)(60_000.0, rfqId: ($this->rfqWith)(2));

    $this->actingAs($this->md)
        ->post("/purchase-orders/{$order->id}/transition", ['to' => 'approved'])
        ->assertSessionHas('error');

    expect($order->fresh()->status)->toBe('pending_approval');
});

it('pr2: allows approval above the threshold with three quotations', function (): void {
    $order = ($this->order)(60_000.0, rfqId: ($this->rfqWith)(3));

    $this->actingAs($this->md)
        ->post("/purchase-orders/{$order->id}/transition", ['to' => 'approved'])
        ->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe('approved');
});

it('pr2: leaves an order below the threshold alone', function (): void {
    // No RFQ, no quotations, and none needed: the control is value-banded.
    $order = ($this->order)(40_000.0);

    $this->actingAs($this->md)
        ->post("/purchase-orders/{$order->id}/transition", ['to' => 'approved']);

    expect($order->fresh()->status)->toBe('approved');
});

it('pr2: measures the threshold in base currency, not the order currency', function (): void {
    // USD 600 is BDT 73,500 — over the BDT 50,000 control. As the bare number `600` it was
    // under it, which is the currency bypass BR-51 exists to close.
    $order = ($this->order)(600.0, $this->usd, $this->usdRate);

    $this->actingAs($this->md)
        ->post("/purchase-orders/{$order->id}/transition", ['to' => 'approved'])
        ->assertSessionHas('error');

    expect($order->fresh()->status)->toBe('pending_approval');
});

it('pr2: accepts a documented override and records it on the order', function (): void {
    $order = ($this->order)(60_000.0);

    $this->actingAs($this->md)->post("/purchase-orders/{$order->id}/transition", [
        'to' => 'approved',
        'override_reason' => 'Sole source — only supplier holding this GRS-certified yarn.',
    ])->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe('approved')
        ->and($order->fresh()->remarks)->toContain('Three-quote override at approval')
        ->and($order->fresh()->remarks)->toContain('Sole source');
});

it('pr2: cannot be bypassed by posting the approval directly', function (): void {
    // The route is the only way in, and it is the same guard either way: no form involved.
    $order = ($this->order)(60_000.0);

    $this->actingAs($this->md)
        ->postJson("/purchase-orders/{$order->id}/transition", ['to' => 'approved']);

    expect($order->fresh()->status)->toBe('pending_approval')
        ->and(DB::table('purchase_orders')->where('id', $order->id)->value('status'))
        ->toBe('pending_approval');
});
