<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\PurchaseReturn;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * GAP P-03 — purchase returns (PR-7): only from a posted receipt, only up to what the lot still
 * holds; posting writes a negative ledger movement and reduces the lot balance; a debit note
 * reference is captured for Accounts.
 */
beforeEach(function (): void {
    $this->purchaser = User::query()->where('email', 'purchase@octapussolution.com')->firstOrFail();
    $this->planner = User::query()->where('email', 'planner@octapussolution.com')->firstOrFail();

    $this->grn = Grn::query()->where('status', 'posted')
        ->whereExists(fn ($q) => $q->selectRaw('1')->from('stock_lots as sl')
            ->join('grn_lines as gl', 'gl.id', '=', 'sl.grn_line_id')
            ->whereColumn('gl.grn_id', 'grns.id')->where('sl.balance_qty', '>', 0))
        ->orderBy('id')->firstOrFail();

    $this->lot = DB::table('stock_lots as sl')
        ->join('grn_lines as gl', 'gl.id', '=', 'sl.grn_line_id')
        ->where('gl.grn_id', $this->grn->id)->where('sl.balance_qty', '>', 0)
        ->orderBy('sl.id')->first(['sl.id', 'sl.lot_no', 'sl.item_id', 'sl.balance_qty', 'sl.unit_cost', 'gl.rate']);

    $this->draft = function (float $qty, array $overrides = []): PurchaseReturn {
        $this->actingAs($this->purchaser)->post('/purchase-returns', [
            'grn_id' => $this->grn->id,
            'returned_on' => now()->toDateString(),
            'reason' => 'Off-shade against the approved swatch',
            'lines' => [['lot_id' => $this->lot->id, 'qty' => $qty]],
            ...$overrides,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return PurchaseReturn::query()->latest('id')->firstOrFail();
    };
});

it('drafts a return against a posted receipt, valued at the receipt rate', function (): void {
    $return = ($this->draft)(2);

    expect($return->status)->toBe('draft')
        ->and($return->number)->toBeNull()
        ->and($return->supplier_id)->toBe($this->grn->supplier_id)
        ->and($return->lines)->toHaveCount(1)
        ->and((float) $return->lines->first()->rate)->toBe((float) $this->lot->rate)
        ->and($return->lines->first()->item_id)->toBe((int) $this->lot->item_id);

    $this->get("/purchase-returns/{$return->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Procurement/PurchaseReturns/Show')->has('lines', 1));
});

it('refuses a draft receipt and a quantity beyond what the lot holds', function (): void {
    $draftGrn = Grn::query()->where('status', 'draft')->first();

    if ($draftGrn !== null) {
        $this->actingAs($this->purchaser)->post('/purchase-returns', [
            'grn_id' => $draftGrn->id, 'returned_on' => now()->toDateString(), 'reason' => 'x',
            'lines' => [['lot_id' => $this->lot->id, 'qty' => 1]],
        ])->assertSessionHasErrors('grn_id');
    }

    $this->actingAs($this->purchaser)->post('/purchase-returns', [
        'grn_id' => $this->grn->id, 'returned_on' => now()->toDateString(), 'reason' => 'x',
        'lines' => [['lot_id' => $this->lot->id, 'qty' => (float) $this->lot->balance_qty + 1]],
    ])->assertSessionHasErrors('lines.0.qty');
});

it('posts a negative movement at the lot cost, reduces the balance, numbers the return and keeps the debit note', function (): void {
    $return = ($this->draft)(2);
    $before = (float) DB::table('stock_lots')->where('id', $this->lot->id)->value('balance_qty');

    $this->actingAs($this->purchaser)->post("/purchase-returns/{$return->id}/transition", ['to' => 'posted', 'debit_note_no' => 'DN-77'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    $return->refresh();
    $movement = DB::table('stock_ledger')->where('source_type', PurchaseReturn::class)->where('source_id', $return->id)->first();

    expect($return->status)->toBe('posted')
        ->and($return->number)->toStartWith('PRT')
        ->and($return->debit_note_no)->toBe('DN-77')
        ->and($movement)->not->toBeNull()
        ->and($movement->movement_type)->toBe('purchase_return')
        ->and((float) $movement->qty)->toBe(-2.0)
        ->and((float) $movement->unit_cost)->toBe((float) $this->lot->unit_cost)
        ->and((float) DB::table('stock_lots')->where('id', $this->lot->id)->value('balance_qty'))->toBe($before - 2.0);

    // Posted is terminal.
    $this->post("/purchase-returns/{$return->id}/transition", ['to' => 'cancelled', 'reason' => 'oops'])->assertSessionHas('error');
    expect($return->refresh()->status)->toBe('posted');
});

it('re-checks the lot balance under its lock at posting, not only at draft', function (): void {
    $return = ($this->draft)((float) $this->lot->balance_qty);

    // Something else consumed part of the lot after the draft was saved.
    DB::table('stock_lots')->where('id', $this->lot->id)->decrement('balance_qty', 1);

    $this->actingAs($this->purchaser)->post("/purchase-returns/{$return->id}/transition", ['to' => 'posted'])->assertSessionHas('error');

    expect($return->refresh()->status)->toBe('draft')
        ->and(DB::table('stock_ledger')->where('source_type', PurchaseReturn::class)->where('source_id', $return->id)->exists())->toBeFalse();
});

it('cancelling needs a reason; the debit note can be added after posting', function (): void {
    $return = ($this->draft)(1);

    $this->actingAs($this->purchaser)->post("/purchase-returns/{$return->id}/transition", ['to' => 'cancelled'])->assertSessionHasErrors('reason');
    $this->post("/purchase-returns/{$return->id}/transition", ['to' => 'cancelled', 'reason' => 'Supplier agreed a discount instead'])->assertSessionHasNoErrors();
    expect($return->refresh()->status)->toBe('cancelled');

    $posted = ($this->draft)(1);
    $this->post("/purchase-returns/{$posted->id}/transition", ['to' => 'posted'])->assertSessionHasNoErrors();
    expect($posted->refresh()->debit_note_no)->toBeNull();

    $this->put("/purchase-returns/{$posted->id}/debit-note", ['debit_note_no' => 'DN-78'])->assertSessionHasNoErrors();
    expect($posted->refresh()->debit_note_no)->toBe('DN-78');
});

it('is kept from a role without the permission', function (): void {
    $this->actingAs($this->planner)->get('/purchase-returns')->assertForbidden();
    $this->post('/purchase-returns', [])->assertForbidden();
});
