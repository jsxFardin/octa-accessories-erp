<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Currency\ExchangeRateResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BR-58 — the rate a document is booked at is decided by the server from the published
 * reference rate, not taken on trust from a form field.
 *
 * The defect this covers is not cosmetic. Every base-currency figure in the system is
 * `amount × exchange_rate`: BR-50 report totals, the BR-51 approval bands, the "in the books"
 * line on a document. The rate was a free numeric field on ten forms, silently defaulted to
 * `1` wherever a request omitted it, and hard-coded to `1` on the RFQ → purchase-order path —
 * so a USD document could be booked at parity and read as a hundred-and-twentieth of itself
 * everywhere, including inside an authorisation check.
 */
beforeEach(function (): void {
    $this->resolver = app(ExchangeRateResolver::class);
    $this->base = DB::table('currencies')->where('is_base', true)->firstOrFail();
    $this->usd = DB::table('currencies')->where('code', 'USD')->firstOrFail();
    $this->reference = (float) DB::table('exchange_rates')
        ->where('currency_id', $this->usd->id)->orderByDesc('effective_on')->value('rate_to_base');
});

it('books a base-currency document at one', function (): void {
    expect($this->resolver->resolve((int) $this->base->id, null))->toBe(1.0)
        ->and($this->resolver->resolve((int) $this->base->id, 1))->toBe(1.0);
});

it('refuses a base-currency document booked at anything other than one', function (): void {
    // A rate against the currency the books are kept in is a mis-keyed form, and storing it
    // would restate the document against itself.
    expect(fn () => $this->resolver->resolve((int) $this->base->id, 122.5))
        ->toThrow(ValidationException::class);
});

it('books a foreign document at the published rate when the request carries none', function (): void {
    expect($this->resolver->resolve((int) $this->usd->id, null))->toBe(round($this->reference, 8));
});

it('refuses a foreign document booked at parity', function (): void {
    // The whole of the defect in one assertion: `1` on a USD document.
    expect(fn () => $this->resolver->resolve((int) $this->usd->id, 1))
        ->toThrow(ValidationException::class);
});

it('accepts a contracted rate inside the tolerance', function (): void {
    // A bank or contracted rate legitimately differs from the published card by a little.
    $near = round($this->reference * 0.98, 6);

    expect($this->resolver->resolve((int) $this->usd->id, $near))->toBe(round($near, 8));
});

it('refuses a currency with no rate on file rather than booking it at parity', function (): void {
    $currencyId = DB::table('currencies')->insertGetId([
        'code' => 'ZZZ', 'name' => 'Test currency', 'symbol' => 'Z', 'is_base' => false,
    ]);

    expect(fn () => $this->resolver->resolve((int) $currencyId, null))
        ->toThrow(ValidationException::class);
});

it('books the reference rate on a purchase order raised in a foreign currency', function (): void {
    $buyer = User::query()->where('email', 'purchase@maheenlabel.test')->firstOrFail();
    $supplier = DB::table('suppliers')->where('is_active', true)->where('is_approved', true)->firstOrFail();
    $item = DB::table('items')->where('is_active', true)->firstOrFail();

    $before = DB::table('purchase_orders')->count();

    $this->actingAs($buyer)->post('/purchase-orders', [
        'supplier_id' => $supplier->id,
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'order_date' => now()->toDateString(),
        'currency_id' => $this->usd->id,
        // The bypass, posted directly: a USD order claiming parity with the taka.
        'exchange_rate' => 1,
        'lines' => [[
            'item_id' => $item->id,
            'uom_id' => $item->base_uom_id,
            'qty' => 10,
            'rate' => 500,
        ]],
    ])->assertSessionHasErrors('exchange_rate');

    // Refused, not merely flagged: nothing was written.
    expect(DB::table('purchase_orders')->count())->toBe($before);
});
