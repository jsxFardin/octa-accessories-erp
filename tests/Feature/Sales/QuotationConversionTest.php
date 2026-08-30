<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\QuotationConversionService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Q3 / Q5 — a quotation becomes a sales order once.
 *
 * The rule used to be Q3 alone, and `accepted` is terminal: nothing about converting moved
 * the quotation out of it, so the same POST minted a second draft order against an order that
 * had already been produced and delivered. These tests hold the door shut from the server
 * side, because a disabled button is not a rule.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->product = Product::query()->firstOrFail();
    $this->currency = Currency::query()->where('is_base', true)->firstOrFail();
});

function convertibleQuotation(object $test): Quotation
{
    $test->actingAs($test->merchandiser)->post('/quotations', [
        'customer_id' => $test->customer->id,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency_id' => $test->currency->id,
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $test->product->id,
            'description' => '50,000 woven care labels',
            'qty' => 50000,
            'rate_per_m' => 3.25,
            'margin_pct' => 22,
        ]],
    ])->assertSessionHasNoErrors();

    $quotation = Quotation::query()->latest('id')->firstOrFail();

    $test->actingAs($test->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);
    $test->actingAs($test->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'accepted']);

    return $quotation->fresh();
}

/** The refusal a business rule flashed back, as the screen would read it. */
function refusal(string $key = 'quotation'): ?string
{
    $errors = session('errors');

    // A flashed bag is an array until something hydrates it into a ViewErrorBag; assertions
    // in this file do both, so read either shape.
    return $errors instanceof Illuminate\Support\ViewErrorBag
        ? $errors->first($key)
        : ($errors['default']['messages'][$key][0] ?? null);
}

function convert(object $test, Quotation $quotation, ?User $as = null): Illuminate\Testing\TestResponse
{
    return $test->actingAs($as ?? $test->merchandiser)
        ->post("/quotations/{$quotation->id}/convert", [
            'customer_po_no' => 'PO-UAT-0001',
            'delivery_date' => now()->addWeeks(4)->toDateString(),
        ]);
}

it('converts an accepted quotation into exactly one sales order', function (): void {
    $quotation = convertibleQuotation($this);

    convert($this, $quotation)->assertRedirect()->assertSessionHasNoErrors();

    $orders = SalesOrder::query()->where('quotation_id', $quotation->id)->get();

    expect($orders)->toHaveCount(1)
        ->and($orders->first()->customer_id)->toBe($quotation->customer_id)
        ->and($orders->first()->status)->toBe('draft')
        ->and((float) $orders->first()->exchange_rate)->toBe((float) $quotation->exchange_rate)
        ->and($orders->first()->lines()->count())->toBe($quotation->lines()->count());
});

it('refuses a second conversion and creates no second order', function (): void {
    $quotation = convertibleQuotation($this);

    convert($this, $quotation)->assertSessionHasNoErrors();
    $firstOrder = SalesOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();

    $before = SalesOrder::query()->count();

    convert($this, $quotation)->assertSessionHasErrors('quotation');

    expect(SalesOrder::query()->count())->toBe($before)
        ->and(SalesOrder::query()->where('quotation_id', $quotation->id)->count())->toBe(1)
        // F-08's sibling failure: a refused write must leave no lines behind either.
        ->and(DB::table('sales_order_lines')->where('sales_order_id', '!=', $firstOrder->id)
            ->whereIn('sales_order_id', SalesOrder::query()->where('quotation_id', $quotation->id)->select('id'))
            ->count())->toBe(0);
});

it('names the order it already became rather than saying the request was invalid', function (): void {
    $quotation = convertibleQuotation($this);
    convert($this, $quotation)->assertSessionHasNoErrors();

    $order = SalesOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();

    convert($this, $quotation)->assertSessionHasErrors('quotation');

    expect(refusal())->toContain($order->number ?? "draft order #{$order->id}")
        ->and(refusal())->toContain('A second sales order cannot be created');
});

it('cannot be bypassed by a hand-rolled POST that skips the screen', function (): void {
    $quotation = convertibleQuotation($this);
    convert($this, $quotation)->assertSessionHasNoErrors();

    $before = SalesOrder::query()->count();

    // No form state, no page visit, JSON content negotiation — the shape a curl replay of
    // the request takes. Web routes answer a business-rule refusal with the app's own
    // convention (redirect carrying the error, see bootstrap/app.php), so what matters is
    // that it reaches the same guard and writes nothing.
    $this->actingAs($this->merchandiser)
        ->postJson("/quotations/{$quotation->id}/convert", [])
        ->assertSessionHasErrors('quotation');

    expect(SalesOrder::query()->count())->toBe($before)
        ->and(refusal())->toContain('A second sales order cannot be created');
});

it('refuses to convert a quotation that is not accepted', function (): void {
    $quotation = convertibleQuotation($this);
    $quotation->forceFill(['status' => 'sent'])->save();

    $before = SalesOrder::query()->count();

    convert($this, $quotation)->assertSessionHasErrors('quotation');

    expect(SalesOrder::query()->count())->toBe($before)
        ->and(refusal())->toContain('Only an accepted quotation converts');
});

it('lets a replacement order be raised once the first one is cancelled', function (): void {
    $quotation = convertibleQuotation($this);
    convert($this, $quotation)->assertSessionHasNoErrors();

    $order = SalesOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();

    // The one case the schema's one-to-many was ever for (01-domain-model §1).
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail())
        ->post("/sales-orders/{$order->id}/transition", ['to' => 'cancelled', 'close_reason' => 'Customer restructured the PO'])
        ->assertSessionHasNoErrors();

    convert($this, $quotation)->assertSessionHasNoErrors();

    expect(SalesOrder::query()->where('quotation_id', $quotation->id)->count())->toBe(2)
        ->and(SalesOrder::query()->where('quotation_id', $quotation->id)
            ->whereNotIn('status', ['cancelled'])->count())->toBe(1);
});

it('refuses conversion to a user without sales_order.create', function (): void {
    $quotation = convertibleQuotation($this);

    $before = SalesOrder::query()->count();

    convert($this, $quotation, User::query()->where('email', 'operator@octapussolution.com')->firstOrFail())
        ->assertForbidden();

    expect(SalesOrder::query()->count())->toBe($before);
});

it('records the sales order as a creation event, not only as a status change', function (): void {
    $quotation = convertibleQuotation($this);

    convert($this, $quotation)->assertSessionHasNoErrors();

    $order = SalesOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();

    $created = DB::table('audit_logs')
        ->where('auditable_type', SalesOrder::class)
        ->where('auditable_id', $order->id)
        ->where('event', 'created')
        ->first();

    expect($created)->not->toBeNull()
        ->and($created->user_id)->toBe($this->merchandiser->id)
        ->and(json_decode((string) $created->new_values, true)['quotation_id'])->toBe($quotation->id);
});

it('records the conversion against the quotation with the order it became', function (): void {
    $quotation = convertibleQuotation($this);

    convert($this, $quotation)->assertSessionHasNoErrors();

    $order = SalesOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();

    $row = DB::table('audit_logs')
        ->where('auditable_type', Quotation::class)
        ->where('auditable_id', $quotation->id)
        ->where('event', 'converted')
        ->first();

    expect($row)->not->toBeNull();

    $values = json_decode((string) $row->new_values, true);

    expect($values['target_type'])->toBe(SalesOrder::class)
        ->and($values['target_id'])->toBe($order->id)
        ->and($values['customer_po_no'])->toBe('PO-UAT-0001');
});

it('records the quotation itself as a creation event', function (): void {
    $quotation = convertibleQuotation($this);

    expect(DB::table('audit_logs')
        ->where('auditable_type', Quotation::class)
        ->where('auditable_id', $quotation->id)
        ->where('event', 'created')
        ->count())->toBe(1);
});

it('rolls the whole conversion back when a line cannot be written', function (): void {
    $quotation = convertibleQuotation($this);

    // Break the second half of the unit of work: the order row is written first, the lines
    // after it. If the transaction is real, neither survives.
    $line = $quotation->lines()->firstOrFail();
    DB::table('quotation_lines')->where('id', $line->id)->update(['product_id' => null]);

    $before = SalesOrder::query()->count();

    expect(fn () => app(QuotationConversionService::class)
        ->convert($quotation->fresh(), [], $this->merchandiser))
        ->toThrow(Illuminate\Database\QueryException::class);

    expect(SalesOrder::query()->count())->toBe($before);
});

it('shows a converted quotation its order instead of a convert action', function (): void {
    $quotation = convertibleQuotation($this);

    $this->actingAs($this->merchandiser)
        ->get("/quotations/{$quotation->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conversion.convertible', true)
            ->where('conversion.refusal', null));

    convert($this, $quotation)->assertSessionHasNoErrors();

    $order = SalesOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();

    $this->actingAs($this->merchandiser)
        ->get("/quotations/{$quotation->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conversion.convertible', false)
            ->where('conversion.live_orders.0.id', $order->id)
            ->where('orders.0.id', $order->id));
});

it('leaves an existing conversion relationship intact when a duplicate is refused', function (): void {
    $quotation = convertibleQuotation($this);
    convert($this, $quotation)->assertSessionHasNoErrors();

    $order = SalesOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();
    $snapshot = $order->only(['id', 'quotation_id', 'customer_id', 'status', 'total', 'customer_po_no']);

    convert($this, $quotation)->assertSessionHasErrors('quotation');

    expect($order->fresh()->only(['id', 'quotation_id', 'customer_id', 'status', 'total', 'customer_po_no']))
        ->toBe($snapshot);
});
