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

// GAP C-03 — CP-2 AC1 / PR-3 AC6: a certified line is complete, and honours the order's claim.
it('refuses a certified line without its percentage and transaction-certificate number', function (): void {
    $lines = ($this->payload)()['lines'];
    $lines[0]['cert_scheme'] = 'GRS';

    $this->actingAs($this->store)->post('/grns', ($this->payload)(['lines' => $lines]))
        ->assertSessionHasErrors(['lines.0.cert_claim_pct', 'lines.0.cert_document_no']);

    $lines[0]['cert_claim_pct'] = 80;
    $lines[0]['cert_document_no'] = 'TC-GRS-2026-0001';

    $this->actingAs($this->store)->post('/grns', ($this->payload)(['lines' => $lines]))->assertSessionHasNoErrors();
});

it('refuses to receive a line the order bought certified as plain stock', function (): void {
    DB::table('purchase_order_lines')->where('id', $this->poLines[1]->id)->update(['cert_claim' => 'GRS']);

    $this->actingAs($this->store)->post('/grns', ($this->payload)())
        ->assertSessionHasErrors(['lines.1.cert_scheme']);

    $lines = ($this->payload)()['lines'];
    $lines[1] = [...$lines[1], 'cert_scheme' => 'GRS', 'cert_claim_pct' => 100, 'cert_document_no' => 'TC-GRS-2026-0002'];

    $this->actingAs($this->store)->post('/grns', ($this->payload)(['lines' => $lines]))->assertSessionHasNoErrors();
});

// GAP Q-01 — QL-1 / PR-4 AC5: an item inspected on arrival is received into quarantine with
// its inspection raised; the verdict moves the lot and the GRN line's accepted/rejected split.
it('receives an inspect-on-arrival item into quarantine and raises the incoming inspection', function (): void {
    DB::table('items')->where('id', $this->items[0]->id)->update(['requires_incoming_qc' => true]);

    $this->actingAs($this->store)->post('/grns', ($this->payload)())->assertSessionHasNoErrors();
    $grn = Grn::query()->latest('id')->firstOrFail();
    $lots = ($this->lotsOf)($grn);

    expect($lots[0]->status)->toBe('quarantine')
        ->and($lots[1]->status)->toBe('available');

    $line = DB::table('grn_lines')->where('grn_id', $grn->id)->where('line_no', 1)->first();
    $lot = DB::table('stock_lots')->where('grn_line_id', $line->id)->first();
    $pending = DB::table('qc_inspections')->where('lot_id', $lot->id)->where('stage', 'incoming')->first();

    expect($pending)->not->toBeNull()
        ->and($pending->result)->toBe('pending')
        ->and($pending->number)->not->toBeNull()
        ->and((int) $pending->grn_line_id)->toBe((int) $line->id);

    // The inspector's verdict lands on that row and releases the lot.
    $this->actingAs(User::query()->where('email', 'qc@octapussolution.com')->firstOrFail())
        ->post('/qc-inspections', ['stage' => 'incoming', 'lot_id' => $lot->id, 'grn_line_id' => $line->id, 'lot_size' => 7, 'major_found' => 0])
        ->assertSessionHasNoErrors();

    expect(DB::table('qc_inspections')->where('lot_id', $lot->id)->where('stage', 'incoming')->count())->toBe(1)
        ->and(DB::table('qc_inspections')->where('id', $pending->id)->value('result'))->toBe('accepted')
        ->and(DB::table('stock_lots')->where('id', $lot->id)->value('status'))->toBe('available')
        ->and((float) DB::table('grn_lines')->where('id', $line->id)->value('accepted_qty'))->toBeQty(7.0);
});

it('blocks a received lot the incoming inspection rejects and records the rejected quantity', function (): void {
    DB::table('items')->where('id', $this->items[1]->id)->update(['requires_incoming_qc' => true]);

    $this->actingAs($this->store)->post('/grns', ($this->payload)())->assertSessionHasNoErrors();
    $grn = Grn::query()->latest('id')->firstOrFail();
    $line = DB::table('grn_lines')->where('grn_id', $grn->id)->where('line_no', 2)->first();
    $lot = DB::table('stock_lots')->where('grn_line_id', $line->id)->first();

    $this->actingAs(User::query()->where('email', 'qc@octapussolution.com')->firstOrFail())
        ->post('/qc-inspections', ['stage' => 'incoming', 'lot_id' => $lot->id, 'grn_line_id' => $line->id, 'lot_size' => 13, 'major_found' => 13, 'disposition' => 'scrap'])
        ->assertSessionHasNoErrors();

    expect(DB::table('stock_lots')->where('id', $lot->id)->value('status'))->toBe('blocked')
        ->and((float) DB::table('grn_lines')->where('id', $line->id)->value('rejected_qty'))->toBeQty(13.0)
        ->and((float) DB::table('grn_lines')->where('id', $line->id)->value('accepted_qty'))->toBeQty(0.0)
        // QL-1 AC4 — the NCR is drafted against the receipt.
        ->and(DB::table('ncrs')->where('source', 'incoming')->exists())->toBeTrue();
});

// GAP F-02 — FN-4: the bill total must land inside tolerance of what was received at order rates.
it('refuses to approve a bill worth more than the receipt at the order rates', function (): void {
    $this->actingAs($this->store)->post('/grns', ($this->payload)())->assertSessionHasNoErrors();
    $grn = Grn::query()->latest('id')->firstOrFail();
    $accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $base = DB::table('currencies')->where('is_base', true)->value('id');

    $bill = fn (array $lines) => $this->actingAs($accounts)->post('/supplier-bills', [
        'supplier_id' => $this->order->supplier_id, 'po_id' => $this->order->id, 'grn_id' => $grn->id,
        'bill_no' => 'B-'.uniqid(), 'bill_date' => now()->toDateString(), 'currency_id' => $base, 'lines' => $lines,
    ]);

    // Received 7 and 13 at a PO rate of 10 each: 200. A service line pads the bill to 320.
    $bill([
        ['item_id' => $this->items[0]->id, 'description' => 'Line 1', 'qty' => 7, 'rate' => 10],
        ['item_id' => $this->items[1]->id, 'description' => 'Line 2', 'qty' => 13, 'rate' => 10],
        ['item_id' => null, 'description' => 'Handling', 'qty' => 1, 'rate' => 120],
    ])->assertSessionHasNoErrors();
    $padded = App\Modules\Procurement\Models\SupplierBill::query()->latest('id')->firstOrFail();

    $this->post("/supplier-bills/{$padded->id}/transition", ['to' => 'approved'])
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'tolerance'));

    expect($padded->fresh()->status)->toBe('draft');

    $bill([
        ['item_id' => $this->items[0]->id, 'description' => 'Line 1', 'qty' => 7, 'rate' => 10],
        ['item_id' => $this->items[1]->id, 'description' => 'Line 2', 'qty' => 13, 'rate' => 10],
    ])->assertSessionHasNoErrors();
    $fair = App\Modules\Procurement\Models\SupplierBill::query()->latest('id')->firstOrFail();

    $this->post("/supplier-bills/{$fair->id}/transition", ['to' => 'approved'])->assertSessionHas('success');
});
