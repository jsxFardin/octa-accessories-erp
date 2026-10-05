<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * UX audit H-18. A goods receipt was posted in the same step it was typed in: lots, ledger and
 * average all moved on one click, with no draft and no way back. It can now be saved as a
 * draft, which adds no stock, and posted as a separate step. The posting itself is the code
 * that was always there, so a receipt posted either way must come out the same.
 */
beforeEach(function (): void {
    $this->store = User::query()->where('email', 'store@octapussolution.com')->firstOrFail();
    $buyer = User::query()->where('email', 'purchase@octapussolution.com')->firstOrFail();

    $supplier = DB::table('suppliers')->firstOrFail();
    DB::table('suppliers')->where('id', $supplier->id)->update(['is_approved' => true, 'is_active' => true]);

    $this->items = DB::table('items')->whereNotNull('base_uom_id')->limit(2)->get();

    $this->order = PurchaseOrder::query()->create([
        'number' => 'PO-DRAFT-0001',
        'supplier_id' => $supplier->id,
        'factory_unit_id' => DB::table('factory_units')->where('is_active', true)->value('id'),
        'order_date' => now()->toDateString(),
        'currency_id' => DB::table('currencies')->where('is_base', false)->value('id'),
        'exchange_rate' => 122.5,
        'subtotal' => 5000,
        'total' => 5000,
        'status' => 'approved',
        'created_by' => $buyer->id,
    ]);

    foreach ($this->items as $index => $item) {
        DB::table('purchase_order_lines')->insert([
            'po_id' => $this->order->id, 'line_no' => $index + 1, 'item_id' => $item->id,
            'description' => 'Draft line', 'qty' => 1000, 'uom_id' => $item->base_uom_id,
            'rate' => 10, 'amount' => 10000, 'received_qty' => 0,
        ]);
    }

    $this->poLines = DB::table('purchase_order_lines')->where('po_id', $this->order->id)->orderBy('line_no')->get();

    $this->payload = fn (array $overrides = []): array => [
        'supplier_id' => $this->order->supplier_id,
        'po_id' => $this->order->id,
        'warehouse_id' => DB::table('warehouses')->where('is_active', true)->value('id'),
        'received_on' => now()->toDateString(),
        // Uneven values and a landed cost, so the apportionment has something to get wrong.
        'freight_amount' => 37.5,
        'duty_amount' => 12.25,
        'clearing_amount' => 3,
        'lines' => [
            ['po_line_id' => $this->poLines[0]->id, 'item_id' => $this->items[0]->id, 'uom_id' => $this->items[0]->base_uom_id,
                'qty' => 7, 'rate' => 3.3333, 'roll_length_m' => 250, 'supplier_batch_no' => 'B-1'],
            ['po_line_id' => $this->poLines[1]->id, 'item_id' => $this->items[1]->id, 'uom_id' => $this->items[1]->base_uom_id,
                'qty' => 13, 'rate' => 1.7],
        ],
        ...$overrides,
    ];

    $this->lotsOf = fn (Grn $grn) => DB::table('stock_lots as sl')
        ->join('grn_lines as gl', 'gl.id', '=', 'sl.grn_line_id')
        ->where('gl.grn_id', $grn->id)->orderBy('gl.line_no')
        ->get(['sl.unit_cost', 'sl.balance_qty', 'sl.roll_length_m', 'sl.supplier_batch_no', 'sl.status']);
});

it('saves a draft that adds nothing to stock', function (): void {
    $ledger = DB::table('stock_ledger')->count();

    $this->actingAs($this->store)->post('/grns', ($this->payload)(['post' => false]))->assertSessionHasNoErrors();

    $grn = Grn::query()->latest('id')->firstOrFail();

    expect($grn->status)->toBe('draft')
        ->and(DB::table('grn_lines')->where('grn_id', $grn->id)->count())->toBe(2)
        // The roll length waits on the line until there is a lot to carry it.
        ->and((float) DB::table('grn_lines')->where('grn_id', $grn->id)->orderBy('line_no')->value('roll_length_m'))->toBe(250.0)
        ->and(($this->lotsOf)($grn))->toHaveCount(0)
        ->and(DB::table('stock_ledger')->count())->toBe($ledger)
        ->and((float) DB::table('purchase_order_lines')->where('id', $this->poLines[0]->id)->value('received_qty'))->toBe(0.0);
});

it('posts a draft to exactly what a one-step receipt would have produced', function (): void {
    // One step, as before.
    $this->actingAs($this->store)->post('/grns', ($this->payload)())->assertSessionHasNoErrors();
    $direct = Grn::query()->latest('id')->firstOrFail();
    $expected = ($this->lotsOf)($direct);

    // Draft, then post.
    $this->actingAs($this->store)->post('/grns', ($this->payload)(['post' => false]));
    $draft = Grn::query()->latest('id')->firstOrFail();

    $this->actingAs($this->store)->post("/grns/{$draft->id}/post")->assertSessionHas('success');

    $actual = ($this->lotsOf)($draft->refresh());

    expect($draft->status)->toBe('posted')
        ->and($direct->status)->toBe('posted')
        ->and($actual)->toHaveCount(2)
        ->and($actual->map(fn ($lot) => (array) $lot)->all())->toEqual($expected->map(fn ($lot) => (array) $lot)->all())
        // Both receipts are now against the order.
        ->and((float) DB::table('purchase_order_lines')->where('id', $this->poLines[0]->id)->value('received_qty'))->toBe(14.0);
});

it('posts a draft once, however many times Post is pressed', function (): void {
    $this->actingAs($this->store)->post('/grns', ($this->payload)(['post' => false]));
    $draft = Grn::query()->latest('id')->firstOrFail();

    $this->actingAs($this->store)->post("/grns/{$draft->id}/post");
    $this->actingAs($this->store)->post("/grns/{$draft->id}/post")->assertSessionHas('error');

    expect(($this->lotsOf)($draft))->toHaveCount(2);
});

it('lets a draft be corrected, and a posted receipt not', function (): void {
    $this->actingAs($this->store)->post('/grns', ($this->payload)(['post' => false]));
    $draft = Grn::query()->latest('id')->firstOrFail();

    $changed = ($this->payload)();
    $changed['lines'] = [$changed['lines'][0]];
    $changed['lines'][0]['qty'] = 9;
    unset($changed['post']);

    $this->actingAs($this->store)->put("/grns/{$draft->id}", $changed)->assertSessionHasNoErrors();

    expect($draft->refresh()->status)->toBe('draft')
        ->and(DB::table('grn_lines')->where('grn_id', $draft->id)->count())->toBe(1)
        ->and((float) DB::table('grn_lines')->where('grn_id', $draft->id)->value('received_qty'))->toBe(9.0);

    $this->actingAs($this->store)->post("/grns/{$draft->id}/post");
    $this->actingAs($this->store)->put("/grns/{$draft->id}", $changed)->assertSessionHas('error');
    $this->actingAs($this->store)->get("/grns/{$draft->id}/edit")->assertRedirect("/grns/{$draft->id}");
});

it('discards a draft and refuses to discard a posted receipt', function (): void {
    $this->actingAs($this->store)->post('/grns', ($this->payload)(['post' => false]));
    $draft = Grn::query()->latest('id')->firstOrFail();

    $this->actingAs($this->store)->delete("/grns/{$draft->id}")->assertRedirect('/grns');

    expect(Grn::query()->find($draft->id))->toBeNull()
        ->and(DB::table('grn_lines')->where('grn_id', $draft->id)->count())->toBe(0);

    $this->actingAs($this->store)->post('/grns', ($this->payload)());
    $posted = Grn::query()->latest('id')->firstOrFail();

    $this->actingAs($this->store)->delete("/grns/{$posted->id}")->assertSessionHas('error');

    expect(Grn::query()->find($posted->id))->not->toBeNull();
});
