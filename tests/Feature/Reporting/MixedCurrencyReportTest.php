<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Reporting\Queries\ReceivableReport;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * BR-50 — a report that spans currencies must say which, and must not add them up at face value.
 *
 * The reported defect: `INV-26-00005` is USD 11.63 and the receivables report showed
 * `BDT 11.63`, then summed it into a footer beside taka invoices. On the live data that
 * understated outstanding receivables by roughly 23% — a dollar invoice counted as a taka one
 * is not a rounding error, it is a different debt.
 *
 * Two failures, one cause: no report row carried a currency, so the screen fell back to the
 * factory's, and `SUM()` had nothing to tell it the rows were incommensurable.
 */
beforeEach(function (): void {
    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();

    $this->base = DB::table('currencies')->where('is_base', true)->first();
    $this->foreign = DB::table('currencies')->where('is_base', false)->first();

    $order = SalesOrder::query()->firstOrFail();

    $this->makeInvoice = function (int $currencyId, float $rate, float $total, string $number) use ($order): SalesInvoice {
        return SalesInvoice::query()->create([
            'number' => $number,
            'customer_id' => $order->customer_id,
            'sales_order_id' => $order->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_id' => $currencyId,
            'exchange_rate' => $rate,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'received_amount' => 0,
            'status' => 'issued',
            'created_by' => $this->accounts->id,
        ]);
    };

    // One invoice in each currency. 100 foreign at 120 is 12,000 base; 5,000 base is itself.
    ($this->makeInvoice)((int) $this->foreign->id, 120.0, 100.0, 'INV-TEST-FX');
    ($this->makeInvoice)((int) $this->base->id, 1.0, 5000.0, 'INV-TEST-BASE');

    $this->report = app(ReceivableReport::class);
    $this->request = Request::create('/reports/receivables');
});

it('br50: labels every receivable row with the currency it is actually in', function (): void {
    $rows = $this->report->paginate($this->request)->items();

    $fx = collect($rows)->firstWhere('number', 'INV-TEST-FX');
    $base = collect($rows)->firstWhere('number', 'INV-TEST-BASE');

    expect($fx->currency)->toBe($this->foreign->code)
        ->and($base->currency)->toBe($this->base->code);
});

it('br50: converts the receivables total instead of adding currencies at face value', function (): void {
    $totals = $this->report->totals($this->request);

    $faceValue = (float) DB::table('sales_invoices')
        ->whereIn('number', ['INV-TEST-FX', 'INV-TEST-BASE'])->sum('total');

    // 100 foreign + 5,000 base is 5,100 at face value and 17,000 once converted. The report
    // must not report the first number.
    expect($faceValue)->toBe(5100.0)
        ->and($totals['total'])->toBeGreaterThan($faceValue);
});

it('br50: converts at the rate the document itself recorded, not a live one', function (): void {
    // BR-22 — the rate is snapshotted on the invoice. A report that reached for today's rate
    // would restate history every time it was opened.
    DB::table('sales_invoices')->whereIn('number', ['INV-TEST-FX', 'INV-TEST-BASE'])->delete();

    ($this->makeInvoice)((int) $this->foreign->id, 200.0, 10.0, 'INV-TEST-RATE');

    $totals = $this->report->totals($this->request);
    $rows = collect($this->report->paginate($this->request)->items());

    expect($rows->firstWhere('number', 'INV-TEST-RATE'))->not->toBeNull();

    // 10 at the invoice's own rate of 200 is 2,000 — not 10, and not 10 × today's rate.
    $others = (float) DB::table('sales_invoices')
        ->where('number', '!=', 'INV-TEST-RATE')
        ->selectRaw('COALESCE(SUM(total * exchange_rate), 0) as t')->value('t');

    expect(round($totals['total'] - $others, 2))->toBe(2000.0);
});

it('br50: reports the currencies behind a converted total', function (): void {
    $meta = $this->report->totalsMeta($this->request);

    expect($meta['mixed'])->toBeTrue()
        ->and($meta['by_currency'])->toHaveKey($this->foreign->code)
        ->and($meta['by_currency'])->toHaveKey($this->base->code)
        // The breakdown is the *unconverted* figure, so a reader can check the conversion.
        ->and($meta['by_currency'][$this->foreign->code]['total'])->toBe(100.0);
});

it('br50: does not claim a mix when every document is in one currency', function (): void {
    DB::table('sales_invoices')->where('number', 'INV-TEST-FX')->delete();
    DB::table('sales_invoices')->whereNotIn('number', ['INV-TEST-BASE'])->delete();

    $meta = $this->report->totalsMeta($this->request);

    expect($meta['mixed'])->toBeFalse();
});

it('br50: sends the currency and the totals meta to the screen', function (): void {
    $this->actingAs($this->admin)
        ->get('/reports/receivables')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            expect($props['totalsMeta']['mixed'])->toBeTrue();

            foreach ($props['rows']['data'] as $row) {
                expect($row['currency'])->not->toBeEmpty('a receivables row reached the screen with no currency');
            }
        });
});

it('br50: sums quantities without converting them', function (): void {
    // Pieces are pieces whatever the invoice was raised in. Only money is incommensurable.
    $report = app(App\Modules\Reporting\Queries\FulfilmentReport::class);
    $request = Request::create('/reports/fulfilment');

    $totals = $report->totals($request);
    $rows = collect($report->paginate($request)->items());

    if ($rows->isEmpty()) {
        $this->markTestSkipped('No fulfilment rows in the walkthrough.');
    }

    $orderedFaceValue = (float) DB::table('sales_order_lines')->sum('ordered_qty');

    expect(round($totals['ordered_qty'], 2))->toBe(round($orderedFaceValue, 2));
});
