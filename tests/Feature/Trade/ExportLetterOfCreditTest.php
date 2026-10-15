<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Trade\Models\LetterOfCredit;
use App\Modules\Trade\States\ExportLcStateMachine;
use App\Support\States\StateMachine;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Export letters of credit (plan step 1): the credit a customer's bank opens in our favour —
 * advised with the bank's number, accepted once its dates are known, shipped only by a
 * shipment, presented, realised by accounts — covering this customer's orders in its currency.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->purchaser = User::query()->where('email', 'purchase@octapussolution.com')->firstOrFail();
    $this->lab = User::query()->where('email', 'lab@octapussolution.com')->firstOrFail();

    $this->order = SalesOrder::query()->whereNotNull('number')->whereNotIn('status', ['cancelled', 'closed'])->orderBy('id')->firstOrFail();
    // An order of another customer's. The seed holds one order, so a second is written as a
    // copy of it for another customer, for the duration of the test.
    $copy = (array) DB::table('sales_orders')->where('id', $this->order->id)->first();
    unset($copy['id']);
    $copy['number'] = 'SO-TEST-OTHER';
    $customer = (array) DB::table('customers')->where('id', $this->order->customer_id)->first();
    unset($customer['id']);
    $customer['code'] = 'CUS-TEST-OTHER';
    $customer['name'] = 'Another buyer (test)';
    $copy['customer_id'] = DB::table('customers')->insertGetId($customer);
    $this->otherOrder = SalesOrder::query()->findOrFail(DB::table('sales_orders')->insertGetId($copy));

    $this->raise = function (array $overrides = []): LetterOfCredit {
        $this->actingAs($this->merchandiser)->post('/export-lcs', [
            'lc_no' => 'HSBC-EXP-0091',
            'kind' => 'sight',
            'customer_id' => $this->order->customer_id,
            'currency_id' => $this->order->currency_id,
            'amount' => 25000,
            'tolerance_pct' => 5,
            'issued_on' => now()->toDateString(),
            'last_shipment_date' => now()->addDays(40)->toDateString(),
            'expiry_date' => now()->addDays(60)->toDateString(),
            ...$overrides,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return LetterOfCredit::query()->latest('id')->firstOrFail();
    };
});

it('records an advised credit with our number, the bank number required', function (): void {
    $lc = ($this->raise)();

    expect($lc->number)->toStartWith('ELC')
        ->and($lc->direction)->toBe('export')
        ->and($lc->status)->toBe('advised')
        ->and($lc->customer_id)->toBe((int) $this->order->customer_id)
        ->and($lc->supplier_id)->toBeNull()
        ->and($lc->latest_presentation_days)->toBe(21);

    $this->post('/export-lcs', ['kind' => 'sight', 'customer_id' => $this->order->customer_id, 'currency_id' => $this->order->currency_id, 'amount' => 1])
        ->assertSessionHasErrors('lc_no');

    $this->get("/export-lcs/{$lc->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Trade/ExportLettersOfCredit/Show')->where('letter.number', $lc->number));
});

it('keeps export credits off the import list and import credits off the export list', function (): void {
    $lc = ($this->raise)();

    $this->actingAs($this->purchaser)->get('/letters-of-credit')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('letters.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($lc->id)));
    $this->actingAs($this->purchaser)->get("/export-lcs/{$lc->id}")->assertForbidden();

    $import = LetterOfCredit::query()->import()->first();

    if ($import !== null) {
        $this->actingAs($this->merchandiser)->get("/export-lcs/{$import->id}")->assertNotFound();
    }
});

it('accepts only with the dates known, and shipped is never a hand move', function (): void {
    $undated = ($this->raise)(['last_shipment_date' => null, 'expiry_date' => null]);

    $this->actingAs($this->merchandiser)->post("/export-lcs/{$undated->id}/transition", ['status' => 'accepted'])->assertSessionHas('error');
    expect($undated->refresh()->status)->toBe('advised');

    $lc = ($this->raise)();
    $this->post("/export-lcs/{$lc->id}/transition", ['status' => 'accepted'])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect($lc->refresh()->status)->toBe('accepted');

    // Not offered by the form, and refused by the machine without a shipment behind it.
    $this->post("/export-lcs/{$lc->id}/transition", ['status' => 'shipped'])->assertSessionHasErrors('status');
    expect(fn () => StateMachine::asSystem(fn () => app(ExportLcStateMachine::class)->transition($lc, 'shipped')))->toThrow(App\Support\States\TransitionDenied::class);

    StateMachine::asSystem(fn () => app(ExportLcStateMachine::class)->transition($lc->refresh(), 'shipped', ['shipment' => 'ESH-TEST']));
    expect($lc->refresh()->status)->toBe('shipped');

    // Once accepted, the commercial terms are the bank's: an edit keeps them.
    $this->put("/export-lcs/{$lc->id}", ['lc_no' => 'CHANGED', 'kind' => 'usance', 'customer_id' => $lc->customer_id, 'currency_id' => $lc->currency_id, 'amount' => 1, 'remarks' => 'advising note'])
        ->assertSessionHasNoErrors();
    expect($lc->refresh()->lc_no)->toBe('HSBC-EXP-0091')->and((float) $lc->amount)->toBe(25000.0)->and($lc->remarks)->toBe('advising note');
});

it('presents, records a discrepancy, re-presents, and is realised only by accounts with the bank reference', function (): void {
    $lc = ($this->raise)();
    $this->actingAs($this->merchandiser)->post("/export-lcs/{$lc->id}/transition", ['status' => 'accepted']);
    StateMachine::asSystem(fn () => app(ExportLcStateMachine::class)->transition($lc->refresh(), 'shipped', ['shipment' => 'ESH-TEST']));

    $this->post("/export-lcs/{$lc->id}/transition", ['status' => 'presented', 'presented_on' => now()->toDateString()])->assertSessionHasNoErrors();
    expect($lc->refresh()->status)->toBe('presented')->and($lc->presented_on?->toDateString())->toBe(now()->toDateString());

    $this->post("/export-lcs/{$lc->id}/transition", ['status' => 'discrepant'])->assertSessionHas('error');
    $this->post("/export-lcs/{$lc->id}/transition", ['status' => 'discrepant', 'reason' => 'Packing list quantity differs from the invoice'])->assertSessionHasNoErrors();
    expect($lc->refresh()->status)->toBe('discrepant');
    $this->post("/export-lcs/{$lc->id}/transition", ['status' => 'presented'])->assertSessionHasNoErrors();

    // Realising is money arriving: the merchandiser cannot, accounts can, and only with a reference.
    $this->post("/export-lcs/{$lc->id}/transition", ['status' => 'realised', 'realisation_ref' => 'FDBC/2026/118'])->assertSessionHas('error');
    $this->actingAs($this->accounts)->post("/export-lcs/{$lc->id}/transition", ['status' => 'realised'])->assertSessionHas('error');
    $this->post("/export-lcs/{$lc->id}/transition", ['status' => 'realised', 'realisation_ref' => 'FDBC/2026/118'])->assertSessionHasNoErrors();

    expect($lc->refresh()->status)->toBe('realised')->and($lc->realisation_ref)->toBe('FDBC/2026/118')->and($lc->realised_on)->not->toBeNull();

    // Closing is merchandising's housekeeping, not accounts'.
    $this->post("/export-lcs/{$lc->id}/transition", ['status' => 'closed'])->assertSessionHas('error');
    $this->actingAs($this->merchandiser)->post("/export-lcs/{$lc->id}/transition", ['status' => 'closed'])->assertSessionHasNoErrors();
    expect($lc->refresh()->status)->toBe('closed');
});

it('covers only this customer\'s orders in its currency, and an amendment moves the effective dates', function (): void {
    $lc = ($this->raise)();

    $this->actingAs($this->merchandiser)->post("/export-lcs/{$lc->id}/orders", ['sales_order_id' => $this->otherOrder->id])->assertSessionHasErrors('action');

    $foreign = DB::table('currencies')->where('id', '!=', $lc->currency_id)->value('id');
    DB::table('sales_orders')->where('id', $this->order->id)->update(['currency_id' => $foreign]);
    $this->post("/export-lcs/{$lc->id}/orders", ['sales_order_id' => $this->order->id])->assertSessionHasErrors('action');
    DB::table('sales_orders')->where('id', $this->order->id)->update(['currency_id' => $lc->currency_id]);

    $this->post("/export-lcs/{$lc->id}/orders", ['sales_order_id' => $this->order->id])->assertSessionHasNoErrors();
    expect((float) DB::table('lc_sales_orders')->where('lc_id', $lc->id)->where('sales_order_id', $this->order->id)->value('covered_amount'))->toBe((float) $this->order->total);

    // BR-56 — the currency is fixed while an order is covered.
    $this->put("/export-lcs/{$lc->id}", ['lc_no' => $lc->lc_no, 'kind' => 'sight', 'customer_id' => $lc->customer_id, 'currency_id' => $foreign, 'amount' => 25000])->assertSessionHasErrors('currency_id');

    // The order page says it is covered.
    $this->get("/sales-orders/{$this->order->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('letterOfCredit.number', $lc->number));

    $this->post("/export-lcs/{$lc->id}/amend", ['amended_on' => now()->toDateString(), 'amount_delta' => 5000, 'new_expiry_date' => now()->addDays(90)->toDateString(), 'charges_amount' => 120, 'narrative' => 'Extended'])
        ->assertSessionHasNoErrors();
    $lc->refresh();
    expect($lc->currentAmount())->toBe(30000.0)->and($lc->effectiveExpiry())->toBe(now()->addDays(90)->toDateString());

    $this->delete("/export-lcs/{$lc->id}/orders/{$this->order->id}")->assertSessionHasNoErrors();
    expect(DB::table('lc_sales_orders')->where('lc_id', $lc->id)->exists())->toBeFalse();
});

it('is kept from roles without the permission', function (): void {
    $this->actingAs($this->lab)->get('/export-lcs')->assertForbidden();
    $this->actingAs($this->purchaser)->post('/export-lcs', [])->assertForbidden();
});
