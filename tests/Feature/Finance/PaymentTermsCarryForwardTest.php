<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Sales\Models\Quotation;
use Illuminate\Support\Facades\DB;

/**
 * BR-46 — the customer's payment terms decide the invoice due date, so they have to survive
 * the trip from customer to quotation to order.
 *
 * They did not: the quotation form never collected a term, conversion copied the resulting
 * null onto the order, and the invoice fell back to a hard-coded Net 30. Every Net-60
 * customer was billed thirty days early, and nothing on any screen looked wrong.
 */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());
});

it('defaults a quotation to the customer payment terms when none is chosen', function (): void {
    $net60 = DB::table('payment_terms')->where('code', 'NET60')->value('id');
    $customer = DB::table('customers')->where('is_active', true)->firstOrFail();
    DB::table('customers')->where('id', $customer->id)->update(['payment_term_id' => $net60]);

    $product = DB::table('products')->where('customer_id', $customer->id)->first()
        ?? DB::table('products')->firstOrFail();

    $this->post('/quotations', [
        'customer_id' => $customer->id,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $product->id,
            'description' => 'Payment terms carry-forward',
            'qty' => 1000,
            'rate_per_m' => 900,
            'margin_pct' => 20,
        ]],
    ])->assertSessionHasNoErrors();

    expect(Quotation::query()->latest('id')->firstOrFail()->payment_term_id)->toBe($net60);
});

it('reads the customer terms when an older order carries none', function (): void {
    $net60 = DB::table('payment_terms')->where('code', 'NET60')->value('id');

    // An issued, uninvoiced challan against a real order line. Built here rather than found:
    // the seed carries no challans at all, so this test skipped every run — and BR-46's last
    // leg, the one that decides the due date a customer is actually billed to, was the half
    // that never ran.
    $orderLine = DB::table('sales_order_lines as sol')
        ->join('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
        ->orderBy('sol.id')
        ->firstOrFail(['sol.id', 'sol.sales_order_id', 'sol.product_id', 'so.customer_id']);

    $challanId = DB::table('delivery_challans')->insertGetId([
        'number' => 'DC-TERMS-'.uniqid('', false),
        'sales_order_id' => $orderLine->sales_order_id,
        'customer_id' => $orderLine->customer_id,
        'challan_date' => now()->toDateString(),
        'mode' => 'own_fleet',
        'total_cartons' => 1,
        'total_qty' => 100,
        'status' => 'issued',
    ]);

    DB::table('delivery_challan_lines')->insert([
        'delivery_challan_id' => $challanId,
        'line_no' => 1,
        'sales_order_line_id' => $orderLine->id,
        'product_id' => $orderLine->product_id,
        'qty' => 100,
        'cartons' => 1,
    ]);

    $challan = DB::table('delivery_challans')->where('id', $challanId)->firstOrFail();

    DB::table('customers')->where('id', $challan->customer_id)->update(['payment_term_id' => $net60]);
    DB::table('sales_orders')->where('id', $challan->sales_order_id)->update(['payment_term_id' => null]);

    $this->post('/invoices', ['delivery_challan_id' => $challan->id])->assertSessionHasNoErrors();

    $invoice = DB::table('sales_invoices')->where('delivery_challan_id', $challan->id)->firstOrFail();

    expect($invoice->due_date)->toBe(
        Carbon\CarbonImmutable::parse($invoice->invoice_date)->addDays(60)->toDateString(),
    );
});
