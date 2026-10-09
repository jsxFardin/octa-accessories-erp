<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Services\ItemMasterService;
use App\Modules\Product\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * IM-1 — one item master for everything the factory buys, makes, consumes or charges for.
 *
 * The document's example is a drawcord: a finished good made in family 06, counted in pieces,
 * ordered in pieces, produced in metres, packed 100 to an inner and 1,000 to a carton, varying
 * by colour and length, made for one buyer. Every case here is a field of that example and the
 * refusal that protects it.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->actingAs($this->admin);

    $this->families = DB::table('production_families')->pluck('id', 'code');
    $this->uoms = DB::table('uoms')->pluck('id', 'code');
    $this->categories = DB::table('item_categories')->pluck('id', 'code');
    $this->customerId = (int) DB::table('customers')->value('id');
});

/** @return array<string, mixed> */
function drawcordPayload(object $test, array $overrides = []): array
{
    return [
        'name' => 'Drawcord | Polyester | 5 mm | 120 cm | Plastic tip',
        'item_category_id' => $test->categories['FG'],
        'item_type' => 'finished_good',
        'make_or_buy' => 'make',
        'production_family_id' => $test->families['06'],
        'item_group_id' => DB::table('item_groups')->where('production_family_id', $test->families['06'])->where('code', 'DRAWCORD')->value('id'),
        'garment_type' => 'knit',
        'spec_scope' => 'buyer',
        'customer_id' => $test->customerId,
        'material_base' => 'textile',
        'variant_axes' => ['color', 'length'],
        'base_uom_id' => $test->uoms['pcs'],
        'order_uom_id' => $test->uoms['pcs'],
        'pack_pcs_per_inner' => 100,
        'pack_inners_per_carton' => 10,
        'valuation_method' => 'weighted_average',
        'is_lot_tracked' => true,
        'attributes' => ['material' => 'Polyester', 'diameter_mm' => 5, 'construction' => 'braid', 'length_cm' => 120, 'tip_type' => 'Plastic'],
        ...$overrides,
    ];
}

it('creates the drawcord as a draft item with a code from the family series and a make profile', function (): void {
    $test = $this;
    $this->post('/items', drawcordPayload($this))->assertSessionHasNoErrors();

    $item = Item::query()->where('name', 'like', 'Drawcord%')->firstOrFail();

    expect($item->code)->toMatch('/^BC-06-\d{5}$/')
        ->and($item->status)->toBe(Item::DRAFT)
        ->and($item->created_by)->toBe($this->admin->id)
        ->and($item->variant_axes)->toBe(['color', 'length'])
        ->and($item->attributes['diameter_mm'])->toEqual(5)
        ->and($item->pack_pcs_per_inner)->toBe(100)
        ->and($item->pack_inners_per_carton)->toBe(10)
        ->and($item->product)->toBeInstanceOf(Product::class)
        ->and($item->product->code)->toBe($item->code)
        // Buyer-specific and made: the buyer is on the product, which is where orders read it.
        ->and($item->product->customer_id)->toBe($test->customerId);
});

it('numbers two items of one family in sequence and a bought item without a family in its own', function (): void {
    $service = app(ItemMasterService::class);

    $first = $service->create(drawcordPayload($this));
    $second = $service->create(drawcordPayload($this, ['name' => 'Drawcord, cotton']));
    $yarn = $service->create(['name' => 'Polyester yarn 150D', 'item_category_id' => $this->categories['YARN'], 'base_uom_id' => $this->uoms['kg']]);

    expect($second->code)->toBe(preg_replace_callback('/(\d{5})$/', fn ($m) => str_pad((string) ((int) $m[1] + 1), 5, '0', STR_PAD_LEFT), $first->code))
        ->and($yarn->code)->toMatch('/^ITM-00-\d{5}$/')
        ->and($yarn->make_or_buy)->toBe('buy')
        ->and($yarn->product)->toBeNull();
});

it('refuses a made item without a production family', function (): void {
    $this->post('/items', drawcordPayload($this, ['production_family_id' => null, 'item_group_id' => null]))
        ->assertSessionHasErrors('production_family_id');
});

it('refuses a group that belongs to another family', function (): void {
    $zipperGroup = DB::table('item_groups')->where('production_family_id', $this->families['04'])->value('id');

    $this->post('/items', drawcordPayload($this, ['item_group_id' => $zipperGroup]))
        ->assertSessionHasErrors('item_group_id');
});

it('asks for the specification attributes the family requires', function (): void {
    // Diameter is required of every cord (family_attributes); the tip is optional.
    $this->post('/items', drawcordPayload($this, ['attributes' => ['material' => 'Polyester', 'construction' => 'braid']]))
        ->assertSessionHasErrors('attributes.diameter_mm');

    $this->post('/items', drawcordPayload($this, ['attributes' => ['material' => 'Polyester', 'diameter_mm' => 5, 'construction' => 'woven']]))
        ->assertSessionHasErrors('attributes.construction');
});

it('refuses a variant axis the vocabulary does not know', function (): void {
    $this->post('/items', drawcordPayload($this, ['variant_axes' => ['colour']]))
        ->assertSessionHasErrors('variant_axes.0');
});

it('requires a tool to say its cavities and a service its charge basis', function (): void {
    $this->post('/items', ['name' => 'Cord lock mould 4-cavity', 'item_category_id' => $this->categories['TOOL'], 'item_type' => 'tool', 'base_uom_id' => $this->uoms['pcs']])
        ->assertSessionHasErrors('tool_cavities');

    $this->post('/items', ['name' => 'Plating, nickel', 'item_category_id' => $this->categories['SVC'], 'item_type' => 'service', 'base_uom_id' => $this->uoms['pcs']])
        ->assertSessionHasErrors('service_charge_basis');

    $this->post('/items', ['name' => 'Plating, nickel', 'item_category_id' => $this->categories['SVC'], 'item_type' => 'service', 'base_uom_id' => $this->uoms['pcs'], 'service_charge_basis' => 'per_kg'])
        ->assertSessionHasNoErrors();
});

it('requires a buyer-specific bought item to name its buyer', function (): void {
    $payload = ['name' => 'Nominated zipper tape', 'item_category_id' => $this->categories['TAPE'], 'base_uom_id' => $this->uoms['mtr'], 'spec_scope' => 'buyer'];

    $this->post('/items', $payload)->assertSessionHasErrors('customer_id');
    $this->post('/items', [...$payload, 'customer_id' => $this->customerId])->assertSessionHasNoErrors();
});

it('sells a button by the gross and stocks it by the piece', function (): void {
    $payload = drawcordPayload($this, [
        'name' => 'Shank button 24L',
        'production_family_id' => $this->families['05'],
        'item_group_id' => DB::table('item_groups')->where('production_family_id', $this->families['05'])->where('code', 'SHANK')->value('id'),
        'material_base' => 'metal',
        'order_uom_id' => $this->uoms['gross'],
        'attributes' => ['ligne' => 24, 'base_material' => 'brass'],
    ]);

    $this->post('/items', $payload)->assertSessionHasNoErrors();

    $button = Item::query()->where('name', 'Shank button 24L')->firstOrFail();

    // 1 gross = 144 pcs is a seeded global conversion (BR-3).
    $factor = DB::table('uom_conversions')->whereNull('item_id')
        ->where('from_uom_id', $button->order_uom_id)->where('to_uom_id', $button->base_uom_id)->value('factor');

    expect($button->code)->toMatch('/^FS-05-\d{5}$/')
        ->and((float) $factor)->toBe(144.0);
});

it('creates a product through the products screen as a finished good on the item master', function (): void {
    $this->post('/products', [
        'spec_scope' => 'buyer',
        'customer_id' => $this->customerId,
        'name' => 'Nordfjell woven main label',
        'product_type' => 'woven',
        'production_family_id' => $this->families['02'],
        'garment_type' => 'both',
        'attributes' => ['label_type' => 'main'],
    ])->assertSessionHasNoErrors();

    $product = Product::query()->whereHas('item', fn ($q) => $q->where('name', 'Nordfjell woven main label'))->firstOrFail();

    expect($product->item->item_type)->toBe('finished_good')
        ->and($product->item->make_or_buy)->toBe('make')
        ->and($product->item->code)->toMatch('/^PL-02-\d{5}$/')
        ->and($product->item->status)->toBe(Item::DRAFT)
        ->and($product->customer_id)->toBe($this->customerId)
        ->and($product->routing_id)->not->toBeNull();
});

it('allows a standard product with no customer', function (): void {
    $this->post('/products', [
        'spec_scope' => 'standard',
        'name' => 'Stock size label, M',
        'product_type' => 'woven',
        'production_family_id' => $this->families['02'],
        'attributes' => ['label_type' => 'size'],
    ])->assertSessionHasNoErrors();

    $product = Product::query()->whereHas('item', fn ($q) => $q->where('name', 'Stock size label, M'))->firstOrFail();

    expect($product->customer_id)->toBeNull()
        ->and($product->item->spec_scope)->toBe('standard');
});

it('keeps a product with the customer it was created for', function (): void {
    $product = Product::query()->whereNotNull('customer_id')->firstOrFail();
    $other = DB::table('customers')->where('id', '!=', $product->customer_id)->value('id');

    $this->put("/products/{$product->id}", [
        'spec_scope' => 'buyer',
        'customer_id' => $other,
        'name' => $product->name,
        'product_type' => $product->product_type,
        'production_family_id' => $product->item->production_family_id,
        'attributes' => $product->item->attributes,
    ])->assertSessionHasErrors('customer_id');
});
