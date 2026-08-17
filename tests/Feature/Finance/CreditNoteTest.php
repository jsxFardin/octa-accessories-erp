<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\Models\PackingList;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\Models\SalesInvoiceLine;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P2-1 — the corrective document. The identity this suite defends after every operation:
 *
 *   invoice.total = received_amount + Σ(applied credits) + outstanding
 *
 * Returns after invoicing draft a credit note automatically; approval is banded; application
 * recomputes eligibility under the invoice's row lock and refuses over-credit.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->soLineId = (int) $this->jobCard->sales_order_line_id;
    $this->soId = (int) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('sales_order_id');
    $this->customerId = (int) DB::table('sales_orders')->where('id', $this->soId)->value('customer_id');
    $this->currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');
    $this->fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');
});

/** Run the real chain: produce, QC-accept, receive FG, pack $qty, draft the challan. */
function fulfil(object $test, float $qty): DeliveryChallan
{
    $test->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());

    if ($test->jobCard->refresh()->status === JobCard::PLANNED) {
        $states = app(JobCardStateMachine::class);
        $states->transition($test->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'credit walkthrough']);
        $states->transition($test->jobCard->refresh(), JobCard::IN_PRODUCTION);
        $test->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail()
            ->forceFill(['input_qty' => 10000, 'good_qty' => 10000])->save();
        DB::table('sales_order_lines')->where('id', $test->soLineId)->increment('produced_qty', 10000);

        $test->actingAs(User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail());
        $test->post('/qc-inspections', ['job_card_id' => $test->jobCard->id, 'stage' => 'final', 'lot_size' => 500, 'major_found' => 0]);
        $test->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
        $receipt = app(App\Modules\Manufacturing\Services\FgReceiptService::class)
            ->post($test->jobCard->refresh(), 10000, $test->fgWarehouseId, (string) Str::uuid());
        $test->lot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();
    }

    $test->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail());
    $test->post('/packing-lists', ['sales_order_id' => $test->soId]);
    $list = PackingList::query()->latest('id')->firstOrFail();
    $test->post("/packing-lists/{$list->id}/cartons", []);
    $carton = DB::table('cartons')->where('packing_list_id', $list->id)->first();
    $test->post("/packing-lists/{$list->id}/cartons/{$carton->id}/contents", [
        'sales_order_line_id' => $test->soLineId, 'lot_id' => $test->lot->id, 'qty' => $qty,
    ]);
    $test->post("/packing-lists/{$list->id}/transition", ['to' => 'packed']);
    $test->post('/delivery-challans', ['packing_list_id' => $list->id, 'mode' => 'own_fleet']);

    return DeliveryChallan::query()->latest('id')->firstOrFail();
}

/** A hand-built issued invoice with a known round total, for formula-level scenarios. */
function roundInvoice(object $test, float $total): SalesInvoice
{
    $test->actingAs(User::query()->where('email', 'accounts@maheenlabel.test')->firstOrFail());

    $invoice = SalesInvoice::query()->create([
        'customer_id' => $test->customerId,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $test->currencyId,
        'exchange_rate' => 1,
        'subtotal' => $total, 'tax_amount' => 0, 'total' => $total,
        'status' => 'draft',
    ]);

    SalesInvoiceLine::query()->create([
        'sales_invoice_id' => $invoice->id, 'line_no' => 1,
        'product_id' => (int) $test->jobCard->product_id ?: (int) DB::table('products')->value('id'),
        'description' => 'formula fixture', 'qty' => 1000, 'rate_per_m' => $total, 'tax_amount' => 0, 'amount' => $total,
    ]);

    app(SalesInvoiceStateMachine::class)->transition($invoice, 'issued');

    return $invoice->refresh();
}

/** Draft + approve + apply a credit note of $amount against $invoice, as accounts. */
function creditAndApply(object $test, SalesInvoice $invoice, float $amount, bool $apply = true): CreditNote
{
    $test->actingAs(User::query()->where('email', 'accounts@maheenlabel.test')->firstOrFail());

    $test->post('/credit-notes', [
        'sales_invoice_id' => $invoice->id, 'reason' => 'quality_claim', 'amount' => $amount,
    ]);
    $note = CreditNote::query()->latest('id')->firstOrFail();
    $test->post("/credit-notes/{$note->id}/transition", ['to' => 'approved']);

    if ($apply) {
        $test->post("/credit-notes/{$note->id}/transition", ['to' => 'applied']);
    }

    return $note->refresh();
}

function identity(SalesInvoice $invoice): void
{
    $machine = app(SalesInvoiceStateMachine::class);
    $invoice->refresh();

    expect((float) $invoice->total)
        ->toBeMoney((float) $invoice->received_amount + $machine->appliedCredits($invoice) + $machine->outstanding($invoice));
}

it('drafts a credit note automatically when an invoiced challan is returned', function (): void {
    $challan = fulfil($this, 2000);
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued']);

    $this->actingAs(User::query()->where('email', 'accounts@maheenlabel.test')->firstOrFail());
    $this->post('/invoices', ['delivery_challan_id' => $challan->id]);
    $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
    $this->post("/invoices/{$invoice->id}/transition", ['to' => 'issued']);

    $this->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail());
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'returned', 'return_reason' => 'refused at gate']);

    $note = CreditNote::query()->where('sales_invoice_id', $invoice->id)->first();
    $rate = (float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('rate_per_m');

    expect($note)->not->toBeNull()
        ->and($note->status)->toBe('draft')
        ->and($note->reason)->toBe('return')
        ->and((float) $note->amount)->toBeMoney(2000 / 1000 * $rate)
        // The invoice stands; the credit note is the corrective document.
        ->and($invoice->refresh()->status)->toBe('issued')
        ->and((float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('invoiced_qty'))->toBeQty(2000.0);

    // `returned` is terminal and same-status transitions are no-ops — the retry replays
    // harmlessly and cannot mint a second financial credit.
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'returned', 'return_reason' => 'again'])
        ->assertSessionHas('success');
    expect(CreditNote::query()->where('sales_invoice_id', $invoice->id)->count())->toBe(1)
        ->and((float) DB::table('stock_lots')->where('id', $this->lot->id)->value('balance_qty'))->toBeQty(10000.0);
});

it('creates no credit note for a return before invoicing, and refuses to invoice a returned challan', function (): void {
    $challan = fulfil($this, 1500);
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued']);
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'returned', 'return_reason' => 'wrong colour']);

    expect(CreditNote::query()->count())->toBe(0);

    $this->actingAs(User::query()->where('email', 'accounts@maheenlabel.test')->firstOrFail());
    $this->post('/invoices', ['delivery_challan_id' => $challan->id])->assertSessionHas('error');
    expect(SalesInvoice::query()->count())->toBe(0);
});

it('holds the identity through the 1000/400/300 verification scenario', function (): void {
    $invoice = roundInvoice($this, 1000);
    $machine = app(SalesInvoiceStateMachine::class);

    // received 400
    $this->post('/receipts', [
        'customer_id' => $this->customerId, 'receipt_date' => now()->toDateString(),
        'method' => 'bank_transfer', 'currency_id' => $this->currencyId, 'amount' => 400,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => 400]],
    ])->assertSessionHas('success');

    // credited 300
    creditAndApply($this, $invoice, 300);
    identity($invoice);

    expect($machine->outstanding($invoice->refresh()))->toBeMoney(300.0)
        ->and($invoice->status)->toBe('partially_paid');

    // another 301 credit → the store-side advisory already refuses beyond outstanding
    $this->post('/credit-notes', ['sales_invoice_id' => $invoice->id, 'reason' => 'other', 'amount' => 301])
        ->assertSessionHas('error');

    // receipt of 301 → refused; 300 → allowed and settles the invoice
    $this->post('/receipts', [
        'customer_id' => $this->customerId, 'receipt_date' => now()->toDateString(),
        'method' => 'bank_transfer', 'currency_id' => $this->currencyId, 'amount' => 301,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => 301]],
    ])->assertSessionHasErrors('allocations');

    $this->post('/receipts', [
        'customer_id' => $this->customerId, 'receipt_date' => now()->toDateString(),
        'method' => 'bank_transfer', 'currency_id' => $this->currencyId, 'amount' => 300,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => 300]],
    ])->assertSessionHas('success');

    identity($invoice);
    expect($invoice->refresh()->status)->toBe('paid')
        ->and($machine->outstanding($invoice))->toBeMoney(0.0);
});

it('refuses over-credit at application time, under the invoice lock', function (): void {
    $invoice = roundInvoice($this, 1000);

    // Two notes approved for 700 each — only one fits once the first applies.
    $first = creditAndApply($this, $invoice, 700);
    $second = creditAndApply($this, $invoice, 300, apply: false);
    $third = CreditNote::query()->create([
        'customer_id' => $this->customerId, 'sales_invoice_id' => $invoice->id,
        'note_date' => now()->toDateString(), 'reason' => 'other',
        'currency_id' => $this->currencyId, 'amount' => 700, 'status' => 'approved',
    ]);

    // 700 into 300 outstanding → refused; nothing changes.
    $this->post("/credit-notes/{$third->id}/transition", ['to' => 'applied'])->assertSessionHas('error');
    expect($third->refresh()->status)->toBe('approved');
    identity($invoice);

    // The 300 fits exactly → fully credited invoice becomes paid.
    $this->post("/credit-notes/{$second->id}/transition", ['to' => 'applied'])->assertSessionHas('success');
    identity($invoice);
    expect($invoice->refresh()->status)->toBe('paid')
        ->and($first->refresh()->status)->toBe('applied');

    // Credit after fully settled: a fresh application finds nothing outstanding.
    $late = CreditNote::query()->create([
        'customer_id' => $this->customerId, 'sales_invoice_id' => $invoice->id,
        'note_date' => now()->toDateString(), 'reason' => 'other',
        'currency_id' => $this->currencyId, 'amount' => 10, 'status' => 'approved',
    ]);
    $this->post("/credit-notes/{$late->id}/transition", ['to' => 'applied'])->assertSessionHas('error');
});

it('treats a duplicate application as a no-op, not a second credit', function (): void {
    $invoice = roundInvoice($this, 500);
    $note = creditAndApply($this, $invoice, 200);
    $machine = app(SalesInvoiceStateMachine::class);
    $credited = $machine->appliedCredits($invoice->refresh());

    $this->post("/credit-notes/{$note->id}/transition", ['to' => 'applied'])->assertSessionHas('success');

    expect($machine->appliedCredits($invoice->refresh()))->toBeMoney($credited)->toBeMoney(200.0);
    identity($invoice);
});

it('enforces the approval band: accounts to the band, the MD above it', function (): void {
    $invoice = roundInvoice($this, 100000);

    $this->post('/credit-notes', ['sales_invoice_id' => $invoice->id, 'reason' => 'rate_difference', 'amount' => 60000]);
    $note = CreditNote::query()->latest('id')->firstOrFail();

    // 60,000 > the 50,000 accounts band → refused for accounts…
    $this->post("/credit-notes/{$note->id}/transition", ['to' => 'approved'])->assertSessionHas('error');
    expect($note->refresh()->status)->toBe('draft');

    // …approved by the MD, and numbered on the way.
    $this->actingAs(User::query()->where('email', 'md@maheenlabel.test')->firstOrFail());
    $this->post("/credit-notes/{$note->id}/transition", ['to' => 'approved'])->assertSessionHas('success');

    expect($note->refresh()->status)->toBe('approved')
        ->and($note->number)->not->toBeNull()
        ->and((int) $note->approved_by)->toBe((int) User::query()->where('email', 'md@maheenlabel.test')->value('id'));
});

it('keeps unauthorized users out entirely', function (): void {
    $invoice = roundInvoice($this, 500);
    $this->post('/credit-notes', ['sales_invoice_id' => $invoice->id, 'reason' => 'other', 'amount' => 100]);
    $note = CreditNote::query()->latest('id')->firstOrFail();

    // Dispatch officer holds no credit_note permission — even the view route refuses.
    $this->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail());
    $this->post("/credit-notes/{$note->id}/transition", ['to' => 'approved'])->assertForbidden();

    // The store keeper can read money screens? No credit_note grant either.
    $this->actingAs(User::query()->where('email', 'store@maheenlabel.test')->firstOrFail());
    $this->post('/credit-notes', ['sales_invoice_id' => $invoice->id, 'reason' => 'other', 'amount' => 1])
        ->assertForbidden();

    expect($note->refresh()->status)->toBe('draft');
});

it('audits creation and every transition', function (): void {
    $invoice = roundInvoice($this, 500);
    $note = creditAndApply($this, $invoice, 100);

    $events = DB::table('audit_logs')
        ->where('auditable_type', CreditNote::class)
        ->where('auditable_id', $note->id)
        ->pluck('event');

    expect($events)->toContain('created')
        ->and($events->filter(fn ($event): bool => $event === 'status_changed')->count())->toBeGreaterThanOrEqual(2);
});
