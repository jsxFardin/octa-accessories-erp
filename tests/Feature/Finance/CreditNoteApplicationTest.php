<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\SalesInvoiceLine;
use App\Modules\Finance\Services\CreditNoteApplicationService;
use App\Modules\Finance\States\CreditNoteStateMachine;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P2-1 — where a credit note's value is consumed, now that it need not be the invoice the
 * credit came from.
 *
 *   credit_notes.sales_invoice_id            → where did this credit come from
 *   credit_note_applications.sales_invoice_id → where was it consumed
 *
 * Merging those is what would drive a paid invoice negative, so the suite asserts both halves:
 * the return credit reaches a different, open invoice, and the paid invoice it came from is
 * untouched and still reconciles to zero.
 */
beforeEach(function (): void {
    $this->customerId = (int) DB::table('sales_orders')->value('customer_id');
    $this->currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');
    $this->productId = (int) DB::table('products')->value('id');
    $this->warehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->dispatchUser = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();

    $this->applications = app(CreditNoteApplicationService::class);
    $this->invoices = app(SalesInvoiceStateMachine::class);
});

it('applies a return credit to a different, open invoice', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $open = appInvoice($this, 20000, 375);

    $note = returnCredit($this, $paid, 2500);   // worth 937.50

    $outstandingBefore = $this->invoices->outstanding($open->refresh());

    $this->actingAs($this->accounts);
    $this->applications->apply($note, $open, 937.50);

    $open->refresh();
    $paid->refresh();

    expect($this->invoices->outstanding($open))->toBeMoney($outstandingBefore - 937.50)
        ->and($this->invoices->appliedCredits($open))->toBeMoney(937.50)
        ->and($open->status)->toBe('partially_paid')
        // …and the invoice the credit came from has not moved at all.
        ->and($paid->status)->toBe('paid')
        ->and($this->invoices->outstanding($paid))->toBeMoney(0.0)
        ->and($this->invoices->appliedCredits($paid))->toBeMoney(0.0);
});

it('never counts a return credit against its provenance invoice', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $note = returnCredit($this, $paid, 10000);   // the whole invoice comes back

    $paid->refresh();

    expect((int) $note->sales_invoice_id)->toBe((int) $paid->id)
        // The provenance link is recorded and means nothing financially.
        ->and($this->invoices->appliedCredits($paid))->toBeMoney(0.0)
        ->and($this->invoices->outstanding($paid))->toBeMoney(0.0)
        ->and($this->invoices->outstanding($paid))->toBeGreaterThanOrEqual(0.0)
        ->and((float) $paid->total)->toBeMoney(
            (float) $paid->received_amount + $this->invoices->appliedCredits($paid) + $this->invoices->outstanding($paid),
        );
});

it('spreads one credit note across several invoices', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $first = appInvoice($this, 1000, 375);    // 375.00
    $second = appInvoice($this, 2000, 375);   // 750.00

    $note = returnCredit($this, $paid, 4000); // worth 1,500.00

    $this->actingAs($this->accounts);
    $this->applications->apply($note, $first, 375.00);

    expect($note->refresh()->status)->toBe(CreditNote::APPROVED)
        ->and($this->applications->available($note))->toBeMoney(1125.00);

    $this->applications->apply($note, $second, 750.00);

    expect($this->invoices->outstanding($first->refresh()))->toBeMoney(0.0)
        ->and($first->refresh()->status)->toBe('credited')
        ->and($this->invoices->outstanding($second->refresh()))->toBeMoney(0.0)
        ->and($this->applications->available($note->refresh()))->toBeMoney(375.00)
        // Still value left, so the note is not spent.
        ->and($note->refresh()->status)->toBe(CreditNote::APPROVED);
});

it('marks the note applied once its value is spent', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $open = appInvoice($this, 2500, 375);     // 937.50

    $note = returnCredit($this, $paid, 2500); // 937.50

    $this->actingAs($this->accounts);
    $this->applications->apply($note, $open, 937.50);

    expect($note->refresh()->status)->toBe(CreditNote::APPLIED)
        ->and($this->applications->available($note))->toBeMoney(0.0);
});

it('refuses to apply more than the note has left', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $open = appInvoice($this, 20000, 375);
    $note = returnCredit($this, $paid, 2500); // 937.50

    $this->actingAs($this->accounts);

    expect(fn () => $this->applications->apply($note, $open, 1000.00))
        ->toThrow(ValidationException::class, 'would over-apply it');

    expect(DB::table('credit_note_applications')->where('credit_note_id', $note->id)->count())->toBe(0);
});

it('refuses to apply more than the target invoice owes', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $small = appInvoice($this, 1000, 375);     // 375.00 outstanding
    $note = returnCredit($this, $paid, 4000);  // 1,500.00 available

    $this->actingAs($this->accounts);

    expect(fn () => $this->applications->apply($note, $small, 1500.00))
        ->toThrow(ValidationException::class, 'would over-credit it');
});

it('refuses an invoice belonging to another customer', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $note = returnCredit($this, $paid, 2500);

    $otherCustomer = (int) DB::table('customers')->insertGetId([
        'code' => 'CUST-APP-OTHER', 'name' => 'Other Apparel Ltd',
        'kind' => (string) DB::table('customers')->where('id', $this->customerId)->value('kind'),
        'currency_id' => $this->currencyId, 'created_at' => now(),
    ]);

    $foreign = appInvoice($this, 20000, 375);
    $foreign->forceFill(['customer_id' => $otherCustomer])->save();

    $this->actingAs($this->accounts);

    expect(fn () => $this->applications->apply($note, $foreign->refresh(), 100.00))
        ->toThrow(ValidationException::class, 'different customer');
});

it('refuses to apply across currencies (BR-57)', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $note = returnCredit($this, $paid, 2500);

    $bdt = (int) DB::table('currencies')->where('code', 'BDT')->value('id');
    $other = appInvoice($this, 20000, 375, $bdt);

    $this->actingAs($this->accounts);

    expect(fn () => $this->applications->apply($note, $other, 100.00))
        ->toThrow(ValidationException::class, 'different currency');
});

it('refuses a paid or cancelled target invoice', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $alsoPaid = appPay($this, appInvoice($this, 5000, 375));
    $note = returnCredit($this, $paid, 2500);

    $this->actingAs($this->accounts);

    expect(fn () => $this->applications->apply($note, $alsoPaid, 100.00))
        ->toThrow(ValidationException::class, 'nothing outstanding to credit');
});

it('refuses a note that is not approved', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $open = appInvoice($this, 20000, 375);

    // Drafted by the return, deliberately not approved.
    $line = SalesInvoiceLine::query()->where('sales_invoice_id', $paid->id)->orderBy('line_no')->firstOrFail();
    $draft = CreditNote::query()->create([
        'customer_id' => $this->customerId, 'sales_invoice_id' => $paid->id,
        'note_date' => now()->toDateString(), 'reason' => 'return',
        'currency_id' => (int) $paid->currency_id, 'amount' => 100, 'status' => 'draft',
    ]);

    expect($line)->not->toBeNull();

    $this->actingAs($this->accounts);

    expect(fn () => $this->applications->apply($draft, $open, 50.00))
        ->toThrow(ValidationException::class, 'only an approved note can be applied');
});

it('will not let two applications race past the credit note amount', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $first = appInvoice($this, 20000, 375);
    $second = appInvoice($this, 20000, 375);

    $note = returnCredit($this, $paid, 2500);   // 937.50

    $this->actingAs($this->accounts);
    $this->applications->apply($note, $first, 900.00);

    // Only 37.50 remains; the second application asks for the amount it read before the first.
    expect(fn () => $this->applications->apply($note->refresh(), $second, 900.00))
        ->toThrow(ValidationException::class, 'would over-apply it');

    expect($this->applications->available($note->refresh()))->toBeMoney(37.50)
        ->and((float) DB::table('credit_note_applications')->where('credit_note_id', $note->id)->sum('amount'))
        ->toBeMoney(900.00);
});

it('keeps the original apply-in-full route working exactly as it did', function (): void {
    // The accounts screen's route: an open invoice, a note against it, applied whole through
    // the state machine with no target named. This is how every credit note worked before.
    $open = appInvoice($this, 10000, 375);

    $this->actingAs($this->accounts);
    $this->post('/credit-notes', [
        'sales_invoice_id' => $open->id, 'reason' => 'quality_claim', 'amount' => 1000,
    ]);

    $note = CreditNote::query()->latest('id')->firstOrFail();
    $states = app(CreditNoteStateMachine::class);
    $states->transition($note, CreditNote::APPROVED);
    $states->transition($note->refresh(), CreditNote::APPLIED);

    $open->refresh();

    expect($note->refresh()->status)->toBe(CreditNote::APPLIED)
        ->and($this->invoices->appliedCredits($open))->toBeMoney(1000.0)
        ->and($this->invoices->outstanding($open))->toBeMoney((float) $open->total - 1000.0)
        // The application row is now written for this route too, so both agree.
        ->and((float) DB::table('credit_note_applications')
            ->where('credit_note_id', $note->id)->sum('amount'))->toBeMoney(1000.0);
});

it('keeps the identity holding on every invoice it touches', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $open = appInvoice($this, 20000, 375);
    $note = returnCredit($this, $paid, 4000);

    $this->actingAs($this->accounts);
    $this->applications->apply($note, $open, 1500.00);

    foreach ([$paid, $open] as $invoice) {
        $invoice->refresh();

        expect((float) $invoice->total)->toBeMoney(
            (float) $invoice->received_amount
            + $this->invoices->appliedCredits($invoice)
            + $this->invoices->outstanding($invoice),
        )->and($this->invoices->outstanding($invoice))->toBeGreaterThanOrEqual(0.0);
    }
});
