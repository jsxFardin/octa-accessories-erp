<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\Inquiry;
use App\Modules\Sales\Models\Quotation;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * F-05 / F-06 / F-09 — the funnel, readable from either end.
 *
 * Three separate failures of the same chain:
 *
 * - **F-05** An inquiry for 1,000 pcs became a quotation for 100,000 pcs and nothing on either
 *   screen could say how. Quoting a different quantity is legitimate — a sample volume quoted
 *   at the minimum order quantity, or at the annual programme the tooling is amortised over —
 *   so the fix is traceability, not a constraint. These tests hold the pairing, and hold the
 *   fact that a differing quantity is *allowed*.
 * - **F-06** A Won inquiry showed its quotations and stopped, so the order it was won with was
 *   reachable only by searching for it.
 * - **F-09** `?inquiry=` naming nothing rendered a blank form in silence.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->product = Product::query()->firstOrFail();
    $this->currency = Currency::query()->where('is_base', true)->firstOrFail();
});

function funnelInquiry(object $test, float $qty = 1000): Inquiry
{
    $customer = App\Modules\MasterData\Models\Customer::query()->active()->firstOrFail();

    $test->actingAs($test->merchandiser)->post('/inquiries', [
        'customer_id' => $customer->id,
        'inquiry_date' => now()->toDateString(),
        'lines' => [[
            'product_id' => $test->product->id,
            'description' => 'Centre-fold satin care label, 40 × 20 mm',
            'qty' => $qty,
            'target_rate_per_m' => 100.0,
        ]],
    ])->assertRedirect();

    return Inquiry::query()->latest('id')->firstOrFail();
}

// --- F-05 -------------------------------------------------------------------------------

it('records which inquiry line a quotation line answers', function (): void {
    $inquiry = funnelInquiry($this);
    $inquiryLine = DB::table('inquiry_lines')->where('inquiry_id', $inquiry->id)->firstOrFail();

    $prefill = $this->actingAs($this->merchandiser)
        ->get("/quotations/create?inquiry={$inquiry->id}")
        ->viewData('page')['props'];

    expect($prefill['inquiryPrefill']['lines'][0]['id'])->toBe((int) $inquiryLine->id);

    $this->actingAs($this->merchandiser)->post('/quotations', [
        'inquiry_id' => $inquiry->id,
        'customer_id' => $inquiry->customer_id,
        'quotation_date' => now()->toDateString(),
        'currency_id' => $this->currency->id,
        'exchange_rate' => 1,
        'lines' => [[
            'inquiry_line_id' => $inquiryLine->id,
            'product_id' => $this->product->id,
            'description' => 'Centre-fold satin care label, 40 × 20 mm',
            // The reported case: quoted at a hundred times the quantity inquired.
            'qty' => 100000,
            'rate_per_m' => 270.2066,
        ]],
    ])->assertSessionHasNoErrors();

    $line = DB::table('quotation_lines')
        ->where('quotation_id', Quotation::query()->latest('id')->value('id'))
        ->firstOrFail();

    expect((int) $line->inquiry_line_id)->toBe((int) $inquiryLine->id);
});

it('allows a quoted quantity that differs from the one inquired', function (): void {
    // Explicit: this is not a constraint. Forcing the two to agree would break ordinary
    // merchandising and lose the customer's actual request.
    $inquiry = funnelInquiry($this, 1000);
    $inquiryLine = DB::table('inquiry_lines')->where('inquiry_id', $inquiry->id)->firstOrFail();

    $this->actingAs($this->merchandiser)->post('/quotations', [
        'inquiry_id' => $inquiry->id,
        'customer_id' => $inquiry->customer_id,
        'quotation_date' => now()->toDateString(),
        'currency_id' => $this->currency->id,
        'exchange_rate' => 1,
        'lines' => [[
            'inquiry_line_id' => $inquiryLine->id,
            'product_id' => $this->product->id,
            'description' => 'Quoted at the minimum order quantity',
            'qty' => 100000,
            'rate_per_m' => 270.2066,
        ]],
    ])->assertSessionHasNoErrors();

    // And the inquiry's own record is untouched — history is not overwritten to make the two
    // documents agree.
    expect((float) DB::table('inquiry_lines')->where('id', $inquiryLine->id)->value('qty'))->toBe(1000.0);
});

it('shows the quotation what the customer originally asked for', function (): void {
    $inquiry = funnelInquiry($this, 1000);
    $inquiryLine = DB::table('inquiry_lines')->where('inquiry_id', $inquiry->id)->firstOrFail();

    $this->actingAs($this->merchandiser)->post('/quotations', [
        'inquiry_id' => $inquiry->id,
        'customer_id' => $inquiry->customer_id,
        'quotation_date' => now()->toDateString(),
        'currency_id' => $this->currency->id,
        'exchange_rate' => 1,
        'lines' => [[
            'inquiry_line_id' => $inquiryLine->id,
            'product_id' => $this->product->id,
            'description' => 'Centre-fold satin care label',
            'qty' => 100000,
            'rate_per_m' => 270.2066,
        ]],
    ])->assertSessionHasNoErrors();

    $quotation = Quotation::query()->latest('id')->firstOrFail();

    $this->actingAs($this->merchandiser)
        ->get("/quotations/{$quotation->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            expect((float) $props['lines'][0]['inquiry_line']['qty'])->toBe(1000.0)
                ->and((float) $props['inquiry']['requested_qty'])->toBe(1000.0)
                ->and((float) $props['inquiry']['quoted_qty'])->toBe(100000.0)
                ->and($props['inquiry']['lines_paired'])->toBe(1);
        });
});

it('refuses to pair a quotation line with an inquiry line from another inquiry', function (): void {
    // The field is an id the client chooses; pointing it at someone else's inquiry line would
    // read that line's quantity onto this document.
    $mine = funnelInquiry($this);
    $theirs = funnelInquiry($this);
    $foreignLine = DB::table('inquiry_lines')->where('inquiry_id', $theirs->id)->firstOrFail();

    $this->actingAs($this->merchandiser)->post('/quotations', [
        'inquiry_id' => $mine->id,
        'customer_id' => $mine->customer_id,
        'quotation_date' => now()->toDateString(),
        'currency_id' => $this->currency->id,
        'exchange_rate' => 1,
        'lines' => [[
            'inquiry_line_id' => $foreignLine->id,
            'product_id' => $this->product->id,
            'description' => 'Pointed at the wrong inquiry',
            'qty' => 5000,
            'rate_per_m' => 3.10,
        ]],
    ])->assertSessionHasNoErrors();

    $line = DB::table('quotation_lines')
        ->where('quotation_id', Quotation::query()->latest('id')->value('id'))
        ->firstOrFail();

    // Dropped rather than refused: provenance is not something the merchandiser typed, and
    // losing it must not lose the quotation.
    expect($line->inquiry_line_id)->toBeNull();
});

// --- F-06 -------------------------------------------------------------------------------

it('shows a won inquiry the sales order it was won with', function (): void {
    $inquiry = funnelInquiry($this);

    $this->actingAs($this->merchandiser)->post('/quotations', [
        'inquiry_id' => $inquiry->id,
        'customer_id' => $inquiry->customer_id,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency_id' => $this->currency->id,
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $this->product->id,
            'description' => 'Care labels',
            'qty' => 40000,
            'rate_per_m' => 3.10,
        ]],
    ])->assertSessionHasNoErrors();

    $quotation = Quotation::query()->latest('id')->firstOrFail();

    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'accepted']);
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/convert", [])->assertRedirect();

    $order = DB::table('sales_orders')->where('quotation_id', $quotation->id)->firstOrFail();

    $this->actingAs($this->merchandiser)
        ->get("/inquiries/{$inquiry->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($order, $quotation): void {
            $props = $page->toArray()['props'];

            expect($props['orders'])->toHaveCount(1)
                ->and((int) $props['orders'][0]['id'])->toBe((int) $order->id)
                ->and((int) $props['orders'][0]['quotation_id'])->toBe((int) $quotation->id)
                // BR-47 — the order's own currency travels with it.
                ->and($props['orders'][0]['currency'])->not->toBeNull();
        });
});

it('supports more than one order from one quotation over its life', function (): void {
    // Q5 lets a cancelled order be re-raised, so this is a list rather than a field.
    $inquiry = funnelInquiry($this);

    $this->actingAs($this->merchandiser)->post('/quotations', [
        'inquiry_id' => $inquiry->id,
        'customer_id' => $inquiry->customer_id,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency_id' => $this->currency->id,
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $this->product->id,
            'description' => 'Care labels',
            'qty' => 40000,
            'rate_per_m' => 3.10,
        ]],
    ])->assertSessionHasNoErrors();

    $quotation = Quotation::query()->latest('id')->firstOrFail();
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'accepted']);
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/convert", [])->assertRedirect();

    DB::table('sales_orders')->where('quotation_id', $quotation->id)->update(['status' => 'cancelled']);

    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/convert", [])->assertRedirect();

    $this->actingAs($this->merchandiser)
        ->get("/inquiries/{$inquiry->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('orders', 2));
});

// --- F-09 -------------------------------------------------------------------------------

it('explains an inquiry context that names nothing instead of rendering a blank form', function (): void {
    $this->actingAs($this->merchandiser)
        ->get('/quotations/create?inquiry=999999')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inquiryPrefill', null)
            ->where('inquiryId', null)
            ->where('contextNotice.tone', 'warning')
            ->where('contextNotice.action_href', '/inquiries')
            ->has('contextNotice.title')
            ->has('contextNotice.body'),
        );
});

it('says nothing when no inquiry context was asked for', function (): void {
    $this->actingAs($this->merchandiser)
        ->get('/quotations/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('contextNotice', null));
});

it('treats every unusable inquiry id the same way', function (): void {
    // Missing, zero, negative and non-numeric all get one sentence. A message that told them
    // apart would answer "does this record exist?" for someone with no permission to ask.
    foreach (['0', '-1', 'abc', '999999'] as $bad) {
        $this->actingAs($this->merchandiser)
            ->get("/quotations/create?inquiry={$bad}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inquiryPrefill', null)
                ->where('inquiryId', null)
                ->has('contextNotice'),
            );
    }
});

it('never preselects a record for an unusable inquiry id', function (): void {
    $this->actingAs($this->merchandiser)
        ->get('/quotations/create?inquiry=999999')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('inquiryPrefill', null));
});
