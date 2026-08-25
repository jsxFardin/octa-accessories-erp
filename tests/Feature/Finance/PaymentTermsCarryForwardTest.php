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
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
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

    $challan = DB::table('delivery_challans')
        ->whereNotNull('sales_order_id')
        ->whereIn('status', ['issued', 'in_transit', 'delivered'])
        ->whereNotIn('id', DB::table('sales_invoices')->whereNotNull('delivery_challan_id')->select('delivery_challan_id'))
        ->first();

    if ($challan === null) {
        $this->markTestSkipped('No uninvoiced challan in the seed to invoice.');
    }

    DB::table('customers')->where('id', $challan->customer_id)->update(['payment_term_id' => $net60]);
    DB::table('sales_orders')->where('id', $challan->sales_order_id)->update(['payment_term_id' => null]);

    $this->post('/invoices', ['delivery_challan_id' => $challan->id])->assertSessionHasNoErrors();

    $invoice = DB::table('sales_invoices')->where('delivery_challan_id', $challan->id)->firstOrFail();

    expect($invoice->due_date)->toBe(
        Carbon\CarbonImmutable::parse($invoice->invoice_date)->addDays(60)->toDateString(),
    );
});
