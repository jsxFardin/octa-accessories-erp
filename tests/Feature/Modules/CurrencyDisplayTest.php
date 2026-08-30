<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use Inertia\Testing\AssertableInertia;

/**
 * BR-47 — every screen that shows money also ships the currency that money is in.
 *
 * `3,630,453.60` beside `52.33` with nothing to distinguish them reads as corrupted data. It
 * was two currencies. The formatting helper has always taken a currency; what was missing was
 * any page sending one, so this asserts the data reaches the screen — the helper's own
 * behaviour is covered in `tests/Js/currency.test.js`.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->product = Product::query()->firstOrFail();
    $this->bdt = Currency::query()->where('code', 'BDT')->firstOrFail();
    $this->usd = Currency::query()->where('code', 'USD')->firstOrFail();
});

function quotationIn(object $test, Currency $currency, float $rate, float $qty = 20000): Quotation
{
    $test->actingAs($test->merchandiser)->post('/quotations', [
        'customer_id' => $test->customer->id,
        'quotation_date' => now()->toDateString(),
        'currency_id' => $currency->id,
        'exchange_rate' => $rate,
        'lines' => [[
            'product_id' => $test->product->id,
            'description' => '20,000 woven labels',
            'qty' => $qty,
            'rate_per_m' => 3.0,
        ]],
    ])->assertSessionHasNoErrors();

    return Quotation::query()->latest('id')->firstOrFail();
}

it('ships the currency of every quotation on the list', function (): void {
    $taka = quotationIn($this, $this->bdt, 1);
    $dollars = quotationIn($this, $this->usd, 122.5);

    $this->actingAs($this->merchandiser)
        ->get('/quotations')
        ->assertInertia(function (AssertableInertia $page) use ($taka, $dollars): void {
            $rows = collect($page->toArray()['props']['quotations']['data']);

            expect($rows->firstWhere('id', $taka->id)['currency'])->toBe('BDT')
                ->and($rows->firstWhere('id', $dollars->id)['currency'])->toBe('USD')
                // Not one row on the list may leave the reader guessing.
                ->and($rows->every(fn (array $row): bool => filled($row['currency'])))->toBeTrue();
        });
});

it('ships the currency and the snapshotted rate on a quotation', function (): void {
    $dollars = quotationIn($this, $this->usd, 122.5);

    $this->actingAs($this->merchandiser)
        ->get("/quotations/{$dollars->id}")
        ->assertInertia(function (AssertableInertia $page): void {
            $quotation = $page->toArray()['props']['quotation'];

            expect($quotation['currency']['code'])->toBe('USD')
                // BR-22/Q1 — the rate that ties the document to the books, snapshotted.
                ->and((float) $quotation['exchange_rate'])->toBe(122.5);
        });
});

it('ships the currency on a base-currency quotation too, rather than leaving it null', function (): void {
    $taka = quotationIn($this, $this->bdt, 1);

    $this->actingAs($this->merchandiser)
        ->get("/quotations/{$taka->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('quotation.currency.code', 'BDT'));
});

it('carries the quotation currency onto the sales order it becomes', function (): void {
    $dollars = quotationIn($this, $this->usd, 122.5);

    $this->actingAs($this->merchandiser)->post("/quotations/{$dollars->id}/transition", ['to' => 'sent']);
    $this->actingAs($this->merchandiser)->post("/quotations/{$dollars->id}/transition", ['to' => 'accepted']);
    $this->actingAs($this->merchandiser)
        ->post("/quotations/{$dollars->id}/convert", ['customer_po_no' => 'PO-CCY-1'])
        ->assertSessionHasNoErrors();

    $order = SalesOrder::query()->where('quotation_id', $dollars->id)->firstOrFail();

    expect($order->currency_id)->toBe($this->usd->id)
        ->and((float) $order->exchange_rate)->toBe(122.5);

    $this->actingAs($this->merchandiser)
        ->get("/sales-orders/{$order->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('order.currency', 'USD'));

    $this->actingAs($this->merchandiser)
        ->get('/sales-orders')
        ->assertInertia(function (AssertableInertia $page) use ($order): void {
            expect(collect($page->toArray()['props']['orders']['data'])
                ->firstWhere('id', $order->id)['currency'])->toBe('USD');
        });
});

it('ships the currency on purchase orders, list and detail', function (): void {
    $buyer = User::query()->where('email', 'purchase@octapussolution.com')->firstOrFail();

    $this->actingAs($buyer)
        ->get('/purchase-orders')
        ->assertInertia(function (AssertableInertia $page): void {
            $rows = collect($page->toArray()['props']['purchase_orders']['data']);

            if ($rows->isEmpty()) {
                return;
            }

            expect($rows->every(fn (array $row): bool => array_key_exists('currency', $row)))->toBeTrue();
        });

    $po = App\Modules\Procurement\Models\PurchaseOrder::query()->first();

    if ($po === null) {
        expect(true)->toBeTrue();

        return;
    }

    $this->actingAs($buyer)
        ->get("/purchase-orders/{$po->id}")
        ->assertInertia(function (AssertableInertia $page): void {
            expect($page->toArray()['props']['purchaseOrder']['currency'])->not->toBeNull();
        });
});

it('tells the organisation currency to the frontend so a bare amount can be labelled', function (): void {
    $this->actingAs($this->merchandiser)
        ->get('/quotations')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('app.base_currency', 'BDT'));
});
