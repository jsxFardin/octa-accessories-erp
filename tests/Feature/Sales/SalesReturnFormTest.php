<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\Models\SalesInvoiceLine;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * The return form asks the server for an invoice's lines after the page has loaded. That answer
 * replaces the page's props, so it has to carry the option lists too — without them the
 * warehouse picker emptied and the return could not be saved (UX audit C-02).
 */
beforeEach(function (): void {
    // Raising a return is the dispatch officer's job; accounts only see the credit it produces.
    $this->actingAs(User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail());

    $total = 250.0;

    $this->invoice = SalesInvoice::query()->create([
        'customer_id' => (int) DB::table('sales_orders')->value('customer_id'),
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => (int) DB::table('currencies')->where('code', 'USD')->value('id'),
        'exchange_rate' => 1,
        'subtotal' => $total, 'tax_amount' => 0, 'total' => $total,
        'status' => 'draft',
    ]);

    SalesInvoiceLine::query()->create([
        'sales_invoice_id' => $this->invoice->id, 'line_no' => 1,
        'product_id' => (int) DB::table('products')->value('id'),
        'description' => 'return form fixture', 'qty' => 5000, 'rate_per_m' => 50,
        'tax_amount' => 0, 'amount' => $total,
    ]);
});

beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail());
});

it('sends the warehouse and invoice lists along with an invoice\'s lines', function (): void {
    $this->get("/sales-returns/invoice/{$this->invoice->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Sales/SalesReturns/Form')
            ->where('invoice.id', $this->invoice->id)
            ->has('lines', 1)
            ->has('warehouses.0.id')
            ->has('invoices'),
        );
});

it('offers the same option lists on the blank form and remembers a preselected invoice', function (): void {
    $this->get("/sales-returns/create?invoice={$this->invoice->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Sales/SalesReturns/Form')
            ->where('preselectInvoiceId', $this->invoice->id)
            ->has('warehouses.0.id'),
        );
});
