<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * P2-1 — an invoice written off by credit is not an invoice that was paid.
 *
 * Applying a credit note for the full value left the invoice reading `paid` with
 * `received_amount = 0`. Receivables counted a quality claim as money collected, and nothing
 * on the screen distinguished it from a customer who settled on time.
 */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());
    $this->states = app(SalesInvoiceStateMachine::class);
});

/**
 * The demo seed stops before invoicing, so this builds the one document under test directly.
 * What is being exercised is the settlement arithmetic, not the invoicing screen.
 */
function issuedInvoice(string $suffix): SalesInvoice
{
    $order = DB::table('sales_orders')->whereNotNull('number')->orderBy('id')->firstOrFail();

    return SalesInvoice::query()->create([
        'number' => "INV-TEST-{$suffix}",
        'customer_id' => $order->customer_id,
        'sales_order_id' => $order->id,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $order->currency_id,
        'exchange_rate' => 1,
        'subtotal' => 10000,
        'tax_amount' => 0,
        'total' => 10000,
        'received_amount' => 0,
        'status' => 'issued',
        'created_by' => 1,
    ]);
}

it('marks an invoice credited when credit alone settles it', function (): void {
    $invoice = issuedInvoice('CREDITED');

    $noteId = DB::table('credit_notes')->insertGetId([
        'number' => "CN-TEST-{$invoice->id}",
        'customer_id' => $invoice->customer_id,
        'sales_invoice_id' => $invoice->id,
        'note_date' => now()->toDateString(),
        'reason' => 'quality_claim',
        'currency_id' => $invoice->currency_id,
        'amount' => $invoice->total,
        'status' => 'applied',
        'approved_by' => 1,
        'created_at' => now(),
    ]);

    // The application itself. `applied` is the note's *state*; what it has been applied **to**
    // is `credit_note_applications`, because a credit note no longer necessarily credits the
    // invoice it names — a customer return credits goods billed on an invoice that may already
    // be paid. Building the note by hand means building the application by hand too; every
    // route in the application writes both together.
    DB::table('credit_note_applications')->insert([
        'credit_note_id' => $noteId,
        'sales_invoice_id' => $invoice->id,
        'amount' => $invoice->total,
        'applied_on' => now()->toDateString(),
        'created_at' => now(),
    ]);

    $this->states->reflectPayment($invoice->refresh());

    expect($invoice->refresh()->status)->toBe('credited')
        ->and((float) $invoice->received_amount)->toBe(0.0)
        ->and($this->states->outstanding($invoice))->toBe(0.0);
});

it('still says paid when the money actually arrived', function (): void {
    $invoice = issuedInvoice('PAID');

    $invoice->forceFill(['received_amount' => $invoice->total])->save();

    $this->states->reflectPayment($invoice->refresh());

    expect($invoice->refresh()->status)->toBe('paid');
});
