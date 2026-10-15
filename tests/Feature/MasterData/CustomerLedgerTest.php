<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/** GAP F-04 — FN-6: the customer page carries the ledger and the ageing of what is owed. */
it('shows the customer\'s ledger and ageing on their page', function (): void {
    $admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $customerId = (int) DB::table('customers')->orderBy('id')->value('id');
    $this->actingAs($admin);

    $invoice = SalesInvoice::query()->create([
        'customer_id' => $customerId,
        'invoice_date' => now()->subDays(70)->toDateString(),
        'due_date' => now()->subDays(40)->toDateString(),
        'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
        'exchange_rate' => 1, 'subtotal' => 800, 'total' => 800, 'status' => 'draft',
    ]);
    DB::table('sales_invoice_lines')->insert([
        'sales_invoice_id' => $invoice->id, 'line_no' => 1, 'product_id' => DB::table('products')->value('id'),
        'description' => 'ledger fixture', 'qty' => 1000, 'rate_per_m' => 800, 'tax_amount' => 0, 'amount' => 800,
    ]);
    app(SalesInvoiceStateMachine::class)->transition($invoice, 'issued');
    // Dated by the database's own calendar: the bucket reads CURDATE(), and the app clock sits in Asia/Dhaka.
    DB::table('sales_invoices')->where('id', $invoice->id)->update(['due_date' => DB::raw('DATE_SUB(CURDATE(), INTERVAL 40 DAY)')]);

    $this->get("/customers/{$customerId}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ledger', fn ($ledger) => collect($ledger)->contains(fn ($row) => $row['kind'] === 'invoice' && (int) $row['document_id'] === $invoice->id))
            ->where('ageing', fn ($ageing) => count($ageing) === 5 && (float) collect($ageing)->firstWhere('bucket', '31_60')['amount'] >= 800.0));
});
