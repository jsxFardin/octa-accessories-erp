<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Trade\Models\ImportShipment;
use App\Modules\Trade\Models\LetterOfCredit;
use App\Modules\Trade\Services\LandedCostAllocator;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
    $this->supplier = DB::table('suppliers')->first();
    $this->currency = DB::table('currencies')->where('is_base', true)->first()
        ?? DB::table('currencies')->first();
});

/** A credit in a given state, without going through the screens to get there. */
function credit(array $attributes = []): LetterOfCredit
{
    return LetterOfCredit::query()->create([
        'number' => 'LC-'.uniqid(),
        'kind' => 'sight',
        'supplier_id' => DB::table('suppliers')->value('id'),
        'currency_id' => DB::table('currencies')->value('id'),
        'amount' => 100000,
        'status' => 'draft',
        ...$attributes,
    ]);
}

function shipment(array $attributes = []): ImportShipment
{
    return ImportShipment::query()->create([
        'number' => 'IMP-'.uniqid(),
        'supplier_id' => DB::table('suppliers')->value('id'),
        'currency_id' => DB::table('currencies')->value('id'),
        'mode' => 'sea',
        'exchange_rate' => 1,
        'goods_value' => 0,
        'status' => 'cleared',
        ...$attributes,
    ]);
}

/**
 * A receipt of two lines with known values, linked to a shipment — the setup every landed
 * cost assertion needs.
 *
 * @return array{grn_id: int, lines: list<int>}
 */
function receiptFor(ImportShipment $shipment, array $lines): array
{
    $warehouse = DB::table('warehouses')->value('id');
    $uom = DB::table('uoms')->value('id');

    $grnId = DB::table('grns')->insertGetId([
        'number' => 'GRN-'.uniqid(),
        'supplier_id' => $shipment->supplier_id,
        'import_shipment_id' => $shipment->id,
        'warehouse_id' => $warehouse,
        'received_on' => now()->toDateString(),
        'status' => 'posted',
        'created_at' => now(),
    ]);

    $ids = [];

    foreach ($lines as $index => $line) {
        $lineId = DB::table('grn_lines')->insertGetId([
            'grn_id' => $grnId,
            'line_no' => $index + 1,
            'item_id' => $line['item_id'] ?? DB::table('items')->value('id'),
            'uom_id' => $uom,
            'received_qty' => $line['qty'],
            'accepted_qty' => $line['qty'],
            'rate' => $line['rate'],
            'landed_rate' => $line['rate'],
        ]);

        DB::table('stock_lots')->insert([
            'lot_no' => 'L-'.uniqid(),
            'item_id' => $line['item_id'] ?? DB::table('items')->value('id'),
            'kind' => 'raw_material',
            'warehouse_id' => $warehouse,
            'uom_id' => $uom,
            'received_qty' => $line['qty'],
            'balance_qty' => $line['qty'],
            'unit_cost' => $line['rate'],
            'grn_line_id' => $lineId,
            'received_on' => now()->toDateString(),
            'status' => 'available',
            'created_at' => now(),
        ]);

        $ids[] = $lineId;
    }

    return ['grn_id' => $grnId, 'lines' => $ids];
}

it('spreads allocable costs across receipt lines by value', function (): void {
    $shipment = shipment();

    // 100 × 50 = 5,000 and 100 × 150 = 15,000 — a 1:3 split of anything spread by value.
    $receipt = receiptFor($shipment, [
        ['qty' => 100, 'rate' => 50],
        ['qty' => 100, 'rate' => 150],
    ]);

    DB::table('import_costs')->insert([
        'shipment_id' => $shipment->id,
        'cost_type' => 'duty',
        'incurred_on' => now()->toDateString(),
        'currency_id' => $shipment->currency_id,
        'exchange_rate' => 1,
        'amount' => 2000,
        'base_amount' => 2000,
        'is_allocable' => true,
        'created_at' => now(),
    ]);

    $result = app(LandedCostAllocator::class)->allocate($shipment->fresh());

    expect($result['allocated'])->toBe(2000.0)
        ->and($result['unallocated'])->toBe(0.0);

    $rates = DB::table('grn_lines')->whereIn('id', $receipt['lines'])->orderBy('id')->pluck('landed_rate');

    // 500 of duty over 100 units = +5; 1,500 over 100 units = +15.
    expect((float) $rates[0])->toBe(55.0)
        ->and((float) $rates[1])->toBe(165.0);

    $lotCosts = DB::table('stock_lots')->whereIn('grn_line_id', $receipt['lines'])->orderBy('id')->pluck('unit_cost');

    expect((float) $lotCosts[0])->toBe(55.0)->and((float) $lotCosts[1])->toBe(165.0);
});

it('leaves a period cost out of the stock', function (): void {
    // Demurrage is somebody's mistake, not part of what a kilo of yarn is worth.
    $shipment = shipment();
    $receipt = receiptFor($shipment, [['qty' => 100, 'rate' => 100]]);

    DB::table('import_costs')->insert([
        [
            'shipment_id' => $shipment->id, 'cost_type' => 'freight', 'incurred_on' => now()->toDateString(),
            'currency_id' => $shipment->currency_id, 'exchange_rate' => 1, 'amount' => 1000,
            'base_amount' => 1000, 'is_allocable' => true, 'created_at' => now(),
        ],
        [
            'shipment_id' => $shipment->id, 'cost_type' => 'demurrage', 'incurred_on' => now()->toDateString(),
            'currency_id' => $shipment->currency_id, 'exchange_rate' => 1, 'amount' => 5000,
            'base_amount' => 5000, 'is_allocable' => false, 'created_at' => now(),
        ],
    ]);

    app(LandedCostAllocator::class)->allocate($shipment->fresh());

    expect((float) DB::table('grn_lines')->where('id', $receipt['lines'][0])->value('landed_rate'))->toBe(110.0);
});

it('is idempotent — running it twice does not stack', function (): void {
    $shipment = shipment();
    $receipt = receiptFor($shipment, [['qty' => 100, 'rate' => 100]]);

    DB::table('import_costs')->insert([
        'shipment_id' => $shipment->id, 'cost_type' => 'duty', 'incurred_on' => now()->toDateString(),
        'currency_id' => $shipment->currency_id, 'exchange_rate' => 1, 'amount' => 1000,
        'base_amount' => 1000, 'is_allocable' => true, 'created_at' => now(),
    ]);

    $allocator = app(LandedCostAllocator::class);

    $allocator->allocate($shipment->fresh());
    $allocator->allocate($shipment->fresh());

    expect((float) DB::table('grn_lines')->where('id', $receipt['lines'][0])->value('landed_rate'))->toBe(110.0)
        ->and(DB::table('landed_cost_allocations')->where('shipment_id', $shipment->id)->count())->toBe(1);
});

it('falls back to the supplier rate when the costs are removed', function (): void {
    $shipment = shipment();
    $receipt = receiptFor($shipment, [['qty' => 100, 'rate' => 100]]);

    DB::table('import_costs')->insert([
        'shipment_id' => $shipment->id, 'cost_type' => 'duty', 'incurred_on' => now()->toDateString(),
        'currency_id' => $shipment->currency_id, 'exchange_rate' => 1, 'amount' => 1000,
        'base_amount' => 1000, 'is_allocable' => true, 'created_at' => now(),
    ]);

    $allocator = app(LandedCostAllocator::class);
    $allocator->allocate($shipment->fresh());

    DB::table('import_costs')->where('shipment_id', $shipment->id)->delete();
    $allocator->allocate($shipment->fresh());

    // A landed rate nothing supports is worse than no landed rate.
    expect((float) DB::table('grn_lines')->where('id', $receipt['lines'][0])->value('landed_rate'))->toBe(100.0)
        ->and((float) DB::table('stock_lots')->where('grn_line_id', $receipt['lines'][0])->value('unit_cost'))->toBe(100.0);
});

it('converts a foreign cost before spreading it', function (): void {
    $shipment = shipment();
    $receipt = receiptFor($shipment, [['qty' => 100, 'rate' => 100]]);

    // A freight bill in USD at 120 is 12,000 of cost, not 100.
    $this->actingAs($this->admin)->post("/import-shipments/{$shipment->id}/costs", [
        'cost_type' => 'freight',
        'incurred_on' => now()->toDateString(),
        'currency_id' => $this->currency->id,
        'exchange_rate' => 120,
        'amount' => 100,
        'is_allocable' => true,
    ])->assertRedirect();

    expect((float) DB::table('import_costs')->where('shipment_id', $shipment->id)->value('base_amount'))->toBe(12000.0);

    $this->actingAs($this->admin)->post("/import-shipments/{$shipment->id}/allocate", ['basis' => 'value'])
        ->assertRedirect();

    expect((float) DB::table('grn_lines')->where('id', $receipt['lines'][0])->value('landed_rate'))->toBe(220.0);
});

it('never rounds a bill away', function (): void {
    // 1,000 over three equal lines is 333.33 three times, which is not 1,000. The last line
    // takes the remainder so the allocation reconciles against the invoice.
    $shipment = shipment();
    receiptFor($shipment, [
        ['qty' => 10, 'rate' => 10],
        ['qty' => 10, 'rate' => 10],
        ['qty' => 10, 'rate' => 10],
    ]);

    DB::table('import_costs')->insert([
        'shipment_id' => $shipment->id, 'cost_type' => 'c_and_f', 'incurred_on' => now()->toDateString(),
        'currency_id' => $shipment->currency_id, 'exchange_rate' => 1, 'amount' => 1000,
        'base_amount' => 1000, 'is_allocable' => true, 'created_at' => now(),
    ]);

    $result = app(LandedCostAllocator::class)->allocate($shipment->fresh());

    expect($result['allocated'])->toBe(1000.0)
        ->and((float) DB::table('landed_cost_allocations')->where('shipment_id', $shipment->id)->sum('amount'))
        ->toBe(1000.0);
});

it('excludes a cancelled receipt from the allocation', function (): void {
    $shipment = shipment();
    $live = receiptFor($shipment, [['qty' => 100, 'rate' => 100]]);
    $dead = receiptFor($shipment, [['qty' => 100, 'rate' => 100]]);

    DB::table('grns')->where('id', $dead['grn_id'])->update(['status' => 'cancelled']);

    DB::table('import_costs')->insert([
        'shipment_id' => $shipment->id, 'cost_type' => 'duty', 'incurred_on' => now()->toDateString(),
        'currency_id' => $shipment->currency_id, 'exchange_rate' => 1, 'amount' => 1000,
        'base_amount' => 1000, 'is_allocable' => true, 'created_at' => now(),
    ]);

    app(LandedCostAllocator::class)->allocate($shipment->fresh());

    // The whole bill lands on the live receipt; nothing is parked on stock that does not exist.
    expect((float) DB::table('grn_lines')->where('id', $live['lines'][0])->value('landed_rate'))->toBe(110.0)
        ->and((float) DB::table('grn_lines')->where('id', $dead['lines'][0])->value('landed_rate'))->toBe(100.0);
});

it('walks a credit through its lifecycle and refuses a jump', function (): void {
    $letter = credit();

    $this->actingAs($this->admin)->post("/letters-of-credit/{$letter->id}/transition", ['status' => 'applied'])
        ->assertRedirect();

    // Straight from applied to shipped skips the bank actually opening it.
    $this->actingAs($this->admin)
        ->postJson("/letters-of-credit/{$letter->id}/transition", ['status' => 'shipped'])
        ->assertStatus(422);

    // And opening without the bank's number leaves a credit no document can be matched to.
    $this->actingAs($this->admin)
        ->postJson("/letters-of-credit/{$letter->id}/transition", ['status' => 'opened'])
        ->assertStatus(422);

    $this->actingAs($this->admin)->post("/letters-of-credit/{$letter->id}/transition", [
        'status' => 'opened',
        'lc_no' => 'BD-LC-99001',
    ])->assertRedirect();

    expect($letter->fresh()->status)->toBe('opened')
        ->and($letter->fresh()->lc_no)->toBe('BD-LC-99001')
        ->and($letter->fresh()->issued_on)->not->toBeNull();
});

it('keeps the commercial terms of a live credit off the edit form', function (): void {
    $letter = credit(['status' => 'opened', 'lc_no' => 'BD-1', 'amount' => 100000]);

    $this->actingAs($this->admin)->put("/letters-of-credit/{$letter->id}", [
        'kind' => 'sight',
        'supplier_id' => $letter->supplier_id,
        'currency_id' => $letter->currency_id,
        'amount' => 999999,
        'lc_no' => 'BD-2',
        'remarks' => 'Bank reference corrected',
    ])->assertRedirect();

    // The value moves through an amendment or not at all.
    expect((float) $letter->fresh()->amount)->toBe(100000.0)
        ->and($letter->fresh()->lc_no)->toBe('BD-2');
});

it('records an amendment and moves the effective dates', function (): void {
    $letter = credit(['status' => 'opened', 'lc_no' => 'BD-1', 'expiry_date' => '2026-09-30']);

    $this->actingAs($this->admin)->post("/letters-of-credit/{$letter->id}/amend", [
        'amended_on' => '2026-08-10',
        'amount_delta' => 25000,
        'new_expiry_date' => '2026-11-30',
        'new_last_shipment_date' => '2026-11-15',
        'charges_amount' => 1800,
        'narrative' => 'Extra 25k and two months',
    ])->assertRedirect();

    $letter->refresh();

    expect($letter->currentAmount())->toBe(125000.0)
        ->and($letter->effectiveExpiry())->toBe('2026-11-30')
        ->and($letter->amendments()->count())->toBe(1);
});

it('refuses to cover an order from another supplier', function (): void {
    $letter = credit();
    $other = DB::table('purchase_orders')->where('supplier_id', '!=', $letter->supplier_id)->first();

    if ($other === null) {
        $this->markTestSkipped('The demo data has no order from a second supplier.');
    }

    $this->actingAs($this->admin)
        ->postJson("/letters-of-credit/{$letter->id}/orders", ['po_id' => $other->id])
        ->assertStatus(422);
});

it('gates allocation behind its own permission', function (): void {
    // Rewriting inventory valuation is not the same right as editing a shipment's dates.
    $officer = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'purchase_officer'))->firstOrFail();

    expect($officer->hasPermission('import_shipment.update'))->toBeTrue()
        ->and($officer->hasPermission('letter_of_credit.open'))->toBeFalse();

    $store = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'store_keeper'))->firstOrFail();

    expect($store->hasPermission('import_shipment.allocate'))->toBeFalse();

    $shipment = shipment();

    $this->actingAs($store)->postJson("/import-shipments/{$shipment->id}/allocate")->assertForbidden();
});

it('links a receipt only from the shipment supplier', function (): void {
    $shipment = shipment();

    $foreign = DB::table('grns')->where('supplier_id', '!=', $shipment->supplier_id)->first();

    if ($foreign !== null) {
        $this->actingAs($this->admin)
            ->postJson("/import-shipments/{$shipment->id}/receipts", ['grn_id' => $foreign->id])
            ->assertStatus(422);
    }

    $own = DB::table('grns')->insertGetId([
        'number' => 'GRN-'.uniqid(),
        'supplier_id' => $shipment->supplier_id,
        'warehouse_id' => DB::table('warehouses')->value('id'),
        'received_on' => now()->toDateString(),
        'status' => 'posted',
        'created_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->post("/import-shipments/{$shipment->id}/receipts", ['grn_id' => $own])
        ->assertRedirect();

    expect(DB::table('grns')->where('id', $own)->value('import_shipment_id'))->toBe($shipment->id);
});
