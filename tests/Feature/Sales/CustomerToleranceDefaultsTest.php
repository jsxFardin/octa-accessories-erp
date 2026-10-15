<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * GAP P-10 — BR-44 / MD-4 AC1 / SL-5 AC4: the delivery band defaults from the customer.
 *
 * `customers.over_tolerance_pct` and `under_tolerance_pct` were stored and never read; every
 * order line took the house setting, so a customer who accepts no over-delivery got 5 % like
 * everyone else and the override gate never fired for them.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->customer->forceFill(['over_tolerance_pct' => 2.5, 'under_tolerance_pct' => 1])->save();
    $this->product = Product::query()->where(fn ($q) => $q->whereNull('customer_id')->orWhere('customer_id', $this->customer->id))->firstOrFail();
    $this->currency = Currency::query()->where('is_base', true)->firstOrFail();
});

it('carries the customer band onto the lines of an order converted from a quotation', function (): void {
    $this->actingAs($this->merchandiser)->post('/quotations', [
        'customer_id' => $this->customer->id,
        'quotation_date' => now()->toDateString(),
        'currency_id' => $this->currency->id,
        'exchange_rate' => 1,
        'lines' => [['product_id' => $this->product->id, 'description' => 'Woven labels', 'qty' => 20000, 'rate_per_m' => 3.0]],
    ])->assertSessionHasNoErrors();

    $quotation = Quotation::query()->latest('id')->firstOrFail();
    $this->post("/quotations/{$quotation->id}/transition", ['to' => 'sent'])->assertSessionHasNoErrors();
    $this->post("/quotations/{$quotation->id}/transition", ['to' => 'accepted'])->assertSessionHasNoErrors();
    $this->post("/quotations/{$quotation->id}/convert", ['customer_po_no' => 'PO-TOL-1'])->assertRedirect();

    $line = DB::table('sales_order_lines')
        ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
        ->where('sales_orders.quotation_id', $quotation->id)
        ->first(['over_tolerance_pct', 'under_tolerance_pct']);

    expect((float) $line->over_tolerance_pct)->toBe(2.5)
        ->and((float) $line->under_tolerance_pct)->toBe(1.0);
});

it('defaults a hand-typed order line to the customer band when none is given, and offers it to the form', function (): void {
    $this->actingAs($this->merchandiser)->post('/sales-orders', [
        'customer_id' => $this->customer->id,
        'customer_po_no' => 'PO-TOL-2',
        'order_date' => now()->toDateString(),
        'currency_id' => $this->currency->id,
        'exchange_rate' => 1,
        'priority' => 'normal',
        'lines' => [['product_id' => $this->product->id, 'ordered_qty' => 10000, 'rate_per_m' => 3.0]],
    ])->assertSessionHasNoErrors();

    $order = SalesOrder::query()->latest('id')->firstOrFail();
    $line = $order->lines()->firstOrFail();

    expect((float) $line->over_tolerance_pct)->toBe(2.5)
        ->and((float) $line->under_tolerance_pct)->toBe(1.0);

    $this->get('/sales-orders/create')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('customers', fn ($customers) => collect($customers)
            ->contains(fn ($row) => (int) $row['id'] === $this->customer->id && (float) $row['over_tolerance_pct'] === 2.5)));
});
