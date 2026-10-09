<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * UX audit C-03. The order form has no specification control, so a line typed by hand reached
 * the server with no `product_spec_id` and was refused — only orders converted from a quotation
 * could be saved. The server now settles the spec from the product.
 */
beforeEach(function (): void {
    $this->sales = User::query()->where('email', 'sales@octapussolution.com')->firstOrFail();
    $this->base = DB::table('currencies')->where('is_base', true)->firstOrFail();

    /** @var Product $product */
    $product = Product::query()->active()->whereHas('currentSpec')->firstOrFail();
    $this->product = $product;

    $this->payload = fn (array $line): array => [
        'customer_id' => $this->product->customer_id,
        'order_date' => now()->toDateString(),
        'currency_id' => $this->base->id,
        'priority' => 'normal',
        'lines' => [[
            'product_id' => $this->product->id,
            'description' => 'QA C-03 line',
            'ordered_qty' => 1000,
            'rate_per_m' => 50,
            ...$line,
        ]],
    ];
});

it('saves a hand-typed order line against the product\'s current specification', function (): void {
    $this->actingAs($this->sales)
        ->post('/sales-orders', ($this->payload)(['product_spec_id' => '']))
        ->assertSessionHasNoErrors();

    $order = SalesOrder::query()->latest('id')->firstOrFail();

    expect((int) $order->lines()->firstOrFail()->product_spec_id)
        ->toBe((int) $this->product->currentSpec->id);
});

it('replaces a specification that belongs to a different product', function (): void {
    // A spec row that exists but belongs to some other product — what an edited line carries
    // after its product is changed.
    // A product is an item plus its make profile, so the copy is made through the item
    // master service, which writes both rows.
    $other = app(App\Modules\MasterData\Services\ItemMasterService::class)->create(
        [...$this->product->item->only(['item_category_id', 'item_type', 'make_or_buy', 'production_family_id', 'base_uom_id', 'status']), 'code' => 'QA-C03-OTHER', 'name' => 'Other product'],
        [...$this->product->only(['customer_id', 'brand_id', 'routing_id', 'product_type'])],
    )->product()->firstOrFail();
    $foreign = $this->product->currentSpec->replicate(['current_key']);
    $foreign->forceFill(['product_id' => $other->id, 'status' => ProductSpec::SUPERSEDED, 'version_no' => 99])->save();

    $this->actingAs($this->sales)
        ->post('/sales-orders', ($this->payload)(['product_spec_id' => $foreign->id]))
        ->assertSessionHasNoErrors();

    $order = SalesOrder::query()->latest('id')->firstOrFail();

    expect((int) $order->lines()->firstOrFail()->product_spec_id)
        ->toBe((int) $this->product->currentSpec->id);
});

it('says which line has a product with no current specification', function (): void {
    ProductSpec::query()->where('product_id', $this->product->id)
        ->where('status', ProductSpec::CURRENT)->update(['status' => 'superseded']);

    $this->actingAs($this->sales)
        ->post('/sales-orders', ($this->payload)([]))
        ->assertSessionHasErrors(['lines.0.product_spec_id' => 'Line 1: this product has no current specification. Open the product and make a specification current, then add it to the order.']);
});
