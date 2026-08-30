<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\Inquiry;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * 05-workflows §1 — the inquiry follows its quotations.
 *
 * The lifecycle was in the specification and in no code: an inquiry answered by an accepted
 * quotation, one of them already carrying a sales order, still read `open`. Older inquiries
 * read `won` only because the demo seeder wrote the column by hand, which is what made the
 * inconsistency visible — two inquiries in the same list, the same situation, different words.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->product = Product::query()->firstOrFail();
    $this->currency = Currency::query()->where('is_base', true)->firstOrFail();
});

function openInquiry(object $test): Inquiry
{
    $test->actingAs($test->merchandiser)->post('/inquiries', [
        'customer_id' => $test->customer->id,
        'inquiry_date' => now()->toDateString(),
        'lines' => [['product_id' => $test->product->id, 'description' => '20,000 woven labels', 'qty' => 20000]],
    ])->assertSessionHasNoErrors();

    $inquiry = Inquiry::query()->latest('id')->firstOrFail();

    $test->actingAs($test->merchandiser)
        ->post("/inquiries/{$inquiry->id}/transition", ['status' => Inquiry::OPEN])
        ->assertSessionHasNoErrors();

    return $inquiry->fresh();
}

function quoteInquiry(object $test, Inquiry $inquiry, float $rate = 3.0): Quotation
{
    $test->actingAs($test->merchandiser)->post('/quotations', [
        'inquiry_id' => $inquiry->id,
        'customer_id' => $test->customer->id,
        'quotation_date' => now()->toDateString(),
        'currency_id' => $test->currency->id,
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $test->product->id,
            'description' => '20,000 woven labels',
            'qty' => 20000,
            'rate_per_m' => $rate,
        ]],
    ])->assertSessionHasNoErrors();

    return Quotation::query()->latest('id')->firstOrFail();
}

function moveQuotation(object $test, Quotation $quotation, string $to, array $extra = []): void
{
    $test->actingAs($test->merchandiser)
        ->post("/quotations/{$quotation->id}/transition", ['to' => $to, ...$extra]);
}

it('moves an inquiry to quoted the moment a quotation answers it', function (): void {
    $inquiry = openInquiry($this);

    expect($inquiry->status)->toBe(Inquiry::OPEN);

    quoteInquiry($this, $inquiry);

    expect($inquiry->fresh()->status)->toBe(Inquiry::QUOTED);
});

it('moves an inquiry to won when its quotation is accepted', function (): void {
    $inquiry = openInquiry($this);
    $quotation = quoteInquiry($this, $inquiry);

    moveQuotation($this, $quotation, 'sent');
    expect($inquiry->fresh()->status)->toBe(Inquiry::QUOTED);

    moveQuotation($this, $quotation, 'accepted');

    expect($inquiry->fresh()->status)->toBe(Inquiry::WON)
        ->and($quotation->fresh()->status)->toBe('accepted');
});

it('records the inquiry transition against the inquiry, naming the quotation that caused it', function (): void {
    $inquiry = openInquiry($this);
    $quotation = quoteInquiry($this, $inquiry);
    moveQuotation($this, $quotation, 'sent');
    moveQuotation($this, $quotation, 'accepted');

    $row = DB::table('audit_logs')
        ->where('auditable_type', Inquiry::class)
        ->where('auditable_id', $inquiry->id)
        ->where('event', 'status_changed')
        ->orderByDesc('id')
        ->first();

    $values = json_decode((string) $row->new_values, true);

    expect($values['status'])->toBe(Inquiry::WON)
        ->and($values['because'])->toBe('quotation')
        ->and($values['quotation_id'])->toBe($quotation->id);
});

it('loses an inquiry when its only quotation is rejected, and carries the reason', function (): void {
    $inquiry = openInquiry($this);
    $quotation = quoteInquiry($this, $inquiry);

    moveQuotation($this, $quotation, 'sent');
    moveQuotation($this, $quotation, 'rejected', ['reject_reason' => 'Customer placed with another mill.']);

    $inquiry = $inquiry->fresh();

    expect($inquiry->status)->toBe(Inquiry::LOST)
        ->and($inquiry->lost_reason)->toBe('Customer placed with another mill.');
});

it('does not lose an inquiry while another quotation on it is still standing', function (): void {
    $inquiry = openInquiry($this);

    $first = quoteInquiry($this, $inquiry, 3.0);
    $second = quoteInquiry($this, $inquiry, 2.8);

    moveQuotation($this, $first, 'sent');
    moveQuotation($this, $first, 'rejected', ['reject_reason' => 'Too expensive.']);

    // The second quotation is still a live draft — the customer has not gone anywhere.
    expect($inquiry->fresh()->status)->toBe(Inquiry::QUOTED);

    moveQuotation($this, $second, 'sent');
    moveQuotation($this, $second, 'accepted');

    expect($inquiry->fresh()->status)->toBe(Inquiry::WON);
});

it('keeps an inquiry won when a later revision is rejected', function (): void {
    $inquiry = openInquiry($this);
    $accepted = quoteInquiry($this, $inquiry);
    moveQuotation($this, $accepted, 'sent');
    moveQuotation($this, $accepted, 'accepted');

    expect($inquiry->fresh()->status)->toBe(Inquiry::WON);

    $other = quoteInquiry($this, $inquiry, 2.5);
    moveQuotation($this, $other, 'sent');
    moveQuotation($this, $other, 'rejected', ['reject_reason' => 'Superseded.']);

    // `won` is decided; a later rejection does not un-win the customer.
    expect($inquiry->fresh()->status)->toBe(Inquiry::WON);
});

it('leaves a revision on a quoted inquiry alone', function (): void {
    $inquiry = openInquiry($this);
    $quotation = quoteInquiry($this, $inquiry);
    moveQuotation($this, $quotation, 'sent');
    moveQuotation($this, $quotation, 'revised');

    // Q4 made a revision; the inquiry is still being quoted, neither won nor lost.
    expect($inquiry->fresh()->status)->toBe(Inquiry::QUOTED);
});

it('is already won by the time the quotation becomes an order, and stays won', function (): void {
    $inquiry = openInquiry($this);
    $quotation = quoteInquiry($this, $inquiry);
    moveQuotation($this, $quotation, 'sent');
    moveQuotation($this, $quotation, 'accepted');

    expect($inquiry->fresh()->status)->toBe(Inquiry::WON);

    $this->actingAs($this->merchandiser)
        ->post("/quotations/{$quotation->id}/convert", ['customer_po_no' => 'PO-LIFECYCLE-1'])
        ->assertSessionHasNoErrors();

    $order = SalesOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();

    expect($inquiry->fresh()->status)->toBe(Inquiry::WON);

    // Cancelling the order does not un-win the inquiry: the quotation was still accepted.
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail())
        ->post("/sales-orders/{$order->id}/transition", ['to' => 'cancelled', 'close_reason' => 'Buyer restructured.'])
        ->assertSessionHasNoErrors();

    expect($inquiry->fresh()->status)->toBe(Inquiry::WON);
});

it('never moves an inquiry someone has already decided', function (): void {
    $inquiry = openInquiry($this);
    $quotation = quoteInquiry($this, $inquiry);

    $this->actingAs($this->merchandiser)
        ->post("/inquiries/{$inquiry->id}/transition", ['status' => Inquiry::CANCELLED])
        ->assertSessionHasNoErrors();

    moveQuotation($this, $quotation, 'sent');
    moveQuotation($this, $quotation, 'accepted');

    expect($inquiry->fresh()->status)->toBe(Inquiry::CANCELLED);
});

it('leaves no inquiry disagreeing with its own quotations', function (): void {
    // The state the audit found: `Open` on the list beside an accepted quotation. After the
    // backfill migration and the wiring, nothing in the database may say that again.
    $progression = app(App\Modules\Sales\Services\InquiryProgression::class);

    $disagreeing = Inquiry::query()
        ->whereNotIn('status', [Inquiry::CANCELLED, Inquiry::LOST])
        ->get()
        ->filter(function (Inquiry $inquiry) use ($progression): bool {
            $derived = $progression->derivedStatusFor($inquiry->id);

            return $derived !== null && $derived !== $inquiry->status;
        });

    expect($disagreeing->pluck('number', 'id')->all())->toBe([]);
});
