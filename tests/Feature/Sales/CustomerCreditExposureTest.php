<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Services\CreditNoteApplicationService;
use App\Modules\Finance\Services\RefundService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\States\SalesOrderStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * BR-46 — exposure with unspent customer credit netted off.
 *
 * Exposure is what the customer owes. An approved credit note they have not consumed is the
 * business owing them, and leaving it out overstated exposure by its whole value — a customer
 * who had just returned goods could be refused an order against a limit they were, on balance,
 * nowhere near.
 *
 * The other half matters just as much: credit that has been spent must not relieve exposure a
 * second time. An applied note has already reduced an invoice balance; a refunded one has left
 * the bank.
 */
beforeEach(function (): void {
    $this->customerId = (int) DB::table('sales_orders')->value('customer_id');
    $this->currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');
    $this->productId = (int) DB::table('products')->value('id');
    $this->warehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->dispatchUser = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();

    $this->applications = app(CreditNoteApplicationService::class);
    $this->orders = app(SalesOrderStateMachine::class);
});

function exposureOf(object $test): float
{
    /** @var SalesOrder $order */
    $order = SalesOrder::query()->where('customer_id', $test->customerId)->firstOrFail();

    return (float) $test->orders->creditCheck($order->load('customer'))['exposure'];
}

it('nets unspent credit off the customer exposure', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $open = appInvoice($this, 20000, 375);     // 7,500 outstanding

    $before = exposureOf($this);

    $note = returnCredit($this, $paid, 2500);  // 937.50, approved, unspent

    expect($this->applications->availableForCustomer($this->customerId))->toBeMoney(937.50)
        ->and(exposureOf($this))->toBeMoney($before - 937.50)
        ->and((float) $open->refresh()->total)->toBeGreaterThan(0.0);
});

it('does not count a draft credit note', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    appInvoice($this, 20000, 375);

    $before = exposureOf($this);

    // Drafted by the return and deliberately not approved.
    CreditNote::query()->create([
        'customer_id' => $this->customerId, 'sales_invoice_id' => $paid->id,
        'note_date' => now()->toDateString(), 'reason' => 'return',
        'currency_id' => (int) $paid->currency_id, 'amount' => 500, 'status' => 'draft',
    ]);

    expect($this->applications->availableForCustomer($this->customerId))->toBeMoney(0.0)
        ->and(exposureOf($this))->toBeMoney($before);
});

it('stops counting credit once it has been applied, and does not relieve exposure twice', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $open = appInvoice($this, 20000, 375);

    $note = returnCredit($this, $paid, 2500);   // 937.50

    $withUnspentCredit = exposureOf($this);

    $this->actingAs($this->accounts);
    $this->applications->apply($note, $open, 937.50);

    // The credit is gone from the available pool…
    expect($this->applications->availableForCustomer($this->customerId))->toBeMoney(0.0);

    // …and the invoice it reduced is 937.50 smaller, so exposure is unchanged overall. Netting
    // it in both places would have dropped exposure by 1,875.
    expect(exposureOf($this))->toBeMoney($withUnspentCredit);
});

it('stops counting credit once it has been refunded', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    appInvoice($this, 20000, 375);

    $note = returnCredit($this, $paid, 2500);
    $withCredit = exposureOf($this);

    $this->actingAs($this->accounts);
    app(RefundService::class)->post($note, 937.50);

    // The money left the bank, so it is no longer credit the customer can spend — exposure
    // returns to where it was before the credit existed.
    expect($this->applications->availableForCustomer($this->customerId))->toBeMoney(0.0)
        ->and(exposureOf($this))->toBeMoney($withCredit + 937.50);
});

it('counts partial consumption for exactly what is left', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $open = appInvoice($this, 20000, 375);

    $note = returnCredit($this, $paid, 4000);   // 1,500.00

    $this->actingAs($this->accounts);
    $this->applications->apply($note, $open, 900.00);

    expect($this->applications->availableForCustomer($this->customerId))->toBeMoney(600.00);
});

it('never lets credit drive exposure below zero', function (): void {
    // Everything paid, then the whole lot returned: a large credit against no receivable.
    $paid = appPay($this, appInvoice($this, 10000, 375));
    returnCredit($this, $paid, 10000);

    expect(exposureOf($this))->toBeGreaterThanOrEqual(0.0);
});

it('leaves the paid invoice untouched throughout', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $open = appInvoice($this, 20000, 375);

    $received = (float) $paid->received_amount;
    $note = returnCredit($this, $paid, 2500);

    $this->actingAs($this->accounts);
    $this->applications->apply($note, $open, 937.50);

    $paid->refresh();

    expect($paid->status)->toBe('paid')
        ->and((float) $paid->received_amount)->toBeMoney($received)
        ->and(app(App\Modules\Finance\States\SalesInvoiceStateMachine::class)->outstanding($paid))->toBeMoney(0.0);
});
