<?php

declare(strict_types=1);

use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;

/**
 * A spec that saves but cannot be costed is found out on a quotation, by a merchandiser, with
 * nothing on the screen naming the spec field at fault. The web width and the GSM are asked
 * for here instead — and only of the product types whose formulas read them (BR-5, BR-9).
 */
beforeEach(function (): void {
    $this->actingAs(App\Models\User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());
});

/** The seeded catalogue is one woven label; any other type is that product, retyped. */
function specProduct(string $type): Product
{
    $woven = Product::query()->where('product_type', 'woven')->firstOrFail();

    if ($type === 'woven') {
        return $woven;
    }

    $product = $woven->replicate()->fill(['code' => 'PRD-SPEC-'.strtoupper($type), 'product_type' => $type]);
    $product->save();

    return $product;
}

/** @param  array<string, mixed>  $overrides */
function specPayload(array $overrides = []): array
{
    return [
        'label_width_mm' => 10,
        'label_height_mm' => 12,
        'web_width_mm' => 130,
        'selvedge_mm' => 5,
        'lane_gap_mm' => 2,
        'cut_gap_mm' => 2,
        'fabric_gsm' => 120,
        'warp_ratio' => 0.6,
        'colours' => 1,
        'coverage_pct' => 35,
        'bundle_size' => 500,
        'bundles_per_carton' => 20,
        ...$overrides,
    ];
}

it('refuses a woven spec whose web width is the zero the form started with', function (): void {
    $product = specProduct('woven');
    $before = $product->specs()->count();

    $this->post(route('products.specs.store', $product), specPayload(['web_width_mm' => '0.00']))
        ->assertSessionHasErrors('web_width_mm');

    expect($product->specs()->count())->toBe($before);
});

it('refuses a woven spec with no web width even when the ends are typed', function (): void {
    $this->post(route('products.specs.store', specProduct('woven')), specPayload(['web_width_mm' => null, 'ends' => 4]))
        ->assertSessionHasErrors('web_width_mm');
});

it('refuses a woven spec with no fabric GSM', function (string|int|null $gsm): void {
    $this->post(route('products.specs.store', specProduct('woven')), specPayload(['fabric_gsm' => $gsm]))
        ->assertSessionHasErrors('fabric_gsm');
})->with([null, '', 0]);

it('saves a woven spec that has both', function (): void {
    $product = specProduct('woven');

    $this->post(route('products.specs.store', $product), specPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('products.show', $product));

    $spec = ProductSpec::query()->where('product_id', $product->id)->latest('id')->firstOrFail();

    expect((float) $spec->web_width_mm)->toBe(130.0)
        ->and((float) $spec->fabric_gsm)->toBe(120.0);
});

it('does not ask a type that weaves no yarn for a GSM', function (): void {
    $this->post(route('products.specs.store', specProduct('thermal')), specPayload(['fabric_gsm' => null]))
        ->assertSessionHasNoErrors();
});

it('lets a type that prints no ink type the ends instead of a web width', function (): void {
    $product = specProduct('thermal');

    $this->post(route('products.specs.store', $product), specPayload(['web_width_mm' => 0, 'fabric_gsm' => null, 'ends' => 2]))
        ->assertSessionHasNoErrors();

    $spec = ProductSpec::query()->where('product_id', $product->id)->latest('id')->firstOrFail();

    expect($spec->web_width_mm)->toBeNull()
        ->and($spec->ends)->toBe(2);
});

it('refuses that type a spec with neither a web width nor the ends', function (): void {
    $this->post(route('products.specs.store', specProduct('thermal')), specPayload(['web_width_mm' => null, 'fabric_gsm' => null]))
        ->assertSessionHasErrors('web_width_mm');
});

it('still refuses a web too narrow for one label', function (): void {
    $this->post(route('products.specs.store', specProduct('woven')), specPayload(['web_width_mm' => 15]))
        ->assertSessionHasErrors('web_width_mm');
});
