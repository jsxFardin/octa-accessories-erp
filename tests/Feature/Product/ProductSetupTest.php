<?php

declare(strict_types=1);

use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use App\Modules\Product\Models\Routing;
use App\Modules\Product\Services\ProductSetup;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * The setup list answers "will this product cost?", not "does a spec exist?". A product with
 * a current spec, an approved artwork and an active BOM was reported ready while a quotation
 * line for it came back with no rate — each case below is one of the ways that happened.
 */
beforeEach(function (): void {
    $this->actingAs(App\Models\User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());

    $this->product = Product::query()->where('product_type', 'woven')->firstOrFail();
});

/** @return array<string, array<string, mixed>> */
function setupSteps(Product $product): array
{
    return collect(app(ProductSetup::class)->steps($product->fresh()))->keyBy('key')->all();
}

it('reports a fully set-up product as done, with a trial price', function (): void {
    $steps = setupSteps($this->product);

    expect(array_column($steps, 'state'))->each->toBe('done')
        ->and($steps['price']['rate_per_m'])->toBeGreaterThan(0);
});

it('flags a current spec whose geometry yields no ends', function (): void {
    DB::table('product_specs')
        ->where('product_id', $this->product->id)
        ->where('status', ProductSpec::CURRENT)
        ->update(['web_width_mm' => null, 'ends' => null]);

    $steps = setupSteps($this->product);

    expect($steps['spec']['state'])->toBe('attention')
        ->and($steps['spec']['action']['type'])->toBe('new_spec')
        ->and($steps['price']['state'])->toBe('attention');
});

it('flags a woven spec with no fabric GSM', function (): void {
    DB::table('product_specs')
        ->where('product_id', $this->product->id)
        ->where('status', ProductSpec::CURRENT)
        ->update(['fabric_gsm' => null]);

    expect(setupSteps($this->product)['spec'])
        ->state->toBe('attention')
        ->detail->toContain('GSM');
});

it('flags a woven bill of materials with no yarn on it', function (): void {
    $bom = $this->product->activeBom()->with('lines.item.category')->firstOrFail();

    $bom->lines
        ->filter(fn ($line): bool => $line->item->category->item_class === 'yarn')
        ->each->delete();

    expect(setupSteps($this->product)['bom'])
        ->state->toBe('attention')
        ->detail->toContain('no yarn');
});

it('asks for a routing when the product has none', function (): void {
    $this->product->update(['routing_id' => null]);

    expect(setupSteps($this->product)['routing'])
        ->state->toBe('todo')
        ->action->toMatchArray(['type' => 'choose_routing']);
});

it('sets the routing from the product page', function (): void {
    $routing = Routing::query()->where('product_type', 'woven')->firstOrFail();
    $this->product->update(['routing_id' => null]);

    $this->put(route('products.routing.update', $this->product), ['routing_id' => $routing->id])
        ->assertSessionHasNoErrors();

    expect($this->product->fresh()->routing_id)->toBe($routing->id);
});

it('refuses a routing made for another product type', function (): void {
    $other = Routing::query()->where('product_type', 'woven')->firstOrFail()
        ->replicate()->fill(['code' => 'RT-SETUP-FLEXO', 'product_type' => 'flexo', 'is_default' => false]);
    $other->save();

    $this->put(route('products.routing.update', $this->product), ['routing_id' => $other->id])
        ->assertSessionHasErrors('routing_id');
});

it('gives a new product the default routing of its type', function (): void {
    $default = Routing::query()->where('product_type', 'woven')->where('is_default', true)->where('is_active', true)->firstOrFail();

    $this->post(route('products.store'), [
        'customer_id' => $this->product->customer_id,
        'code' => 'PRD-SETUP-NEW',
        'name' => 'Setup test label',
        'product_type' => 'woven',
        'status' => 'development',
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect(Product::query()->whereCode('PRD-SETUP-NEW')->firstOrFail()->routing_id)->toBe($default->id);
});

it('sends the setup list to the product page', function (): void {
    $this->get(route('products.show', $this->product))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Product/Products/Show')
            ->has('setup', 5)
            ->where('setup.0.key', 'spec')
            // The page loads the BOM lines with a narrowed item; the list must not read that
            // as a bill of materials with no yarn on it.
            ->where('setup.2.state', 'done')
            ->where('setup.4.key', 'price')
            ->has('routings'));
});

/*
 * UX audit H-13. Tools were a read-only list: a plate, screen or die could not be registered,
 * corrected or retired from the application, though releasing a job checks them.
 */
it('registers, edits and retires a tool', function (): void {
    $this->actingAs(App\Models\User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());

    $this->post('/tools', [
        'code' => 'TOOL-T-01', 'kind' => 'cutting_die', 'location' => 'Die rack B',
        'life_impressions' => 80000, 'cost' => 9600, 'status' => 'available',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $tool = App\Modules\Product\Models\Tool::query()->where('code', 'TOOL-T-01')->firstOrFail();
    expect($tool->used_impressions)->toBe(0)->and($tool->status)->toBe('available');

    // A second tool cannot take the same code, and a kind the table does not know is refused.
    $this->post('/tools', ['code' => 'TOOL-T-01', 'kind' => 'screen', 'status' => 'available'])->assertSessionHasErrors('code');
    $this->post('/tools', ['code' => 'TOOL-T-02', 'kind' => 'die', 'status' => 'available'])->assertSessionHasErrors('kind');
    // "On a machine" and "Retired" are not set by typing them into the form.
    $this->post('/tools', ['code' => 'TOOL-T-02', 'kind' => 'screen', 'status' => 'scrapped'])->assertSessionHasErrors('status');

    // Its life cannot be set below what it has already run.
    $tool->update(['used_impressions' => 5000]);
    $this->put("/tools/{$tool->id}", ['code' => 'TOOL-T-01', 'kind' => 'cutting_die', 'life_impressions' => 4000, 'status' => 'available'])
        ->assertSessionHasErrors('life_impressions');

    $this->put("/tools/{$tool->id}", ['code' => 'TOOL-T-01', 'kind' => 'cutting_die', 'life_impressions' => 90000, 'status' => 'worn', 'location' => 'Die rack C'])
        ->assertSessionHasNoErrors();
    expect($tool->refresh()->status)->toBe('worn')->and($tool->location)->toBe('Die rack C')->and($tool->used_impressions)->toBe(5000);

    // A tool on a machine is neither re-statused nor retired from the desk.
    $tool->update(['status' => 'in_use']);
    $this->put("/tools/{$tool->id}", ['code' => 'TOOL-T-01', 'kind' => 'cutting_die', 'status' => 'available'])->assertSessionHasNoErrors();
    expect($tool->refresh()->status)->toBe('in_use');
    $this->post("/tools/{$tool->id}/retire")->assertSessionHas('error');

    $tool->update(['status' => 'available']);
    $this->post("/tools/{$tool->id}/retire")->assertSessionHas('success');
    expect($tool->refresh()->status)->toBe('scrapped');

    // Retired is final.
    $this->put("/tools/{$tool->id}", ['code' => 'TOOL-T-01', 'kind' => 'cutting_die', 'status' => 'available'])->assertSessionHas('error');
    expect($tool->refresh()->status)->toBe('scrapped');
});

it('keeps people without the permission from registering a tool', function (): void {
    $this->actingAs(App\Models\User::query()->where('email', 'sales@octapussolution.com')->firstOrFail());

    $this->post('/tools', ['code' => 'TOOL-T-09', 'kind' => 'screen', 'status' => 'available'])->assertForbidden();
});

/*
 * UX audit M-20. The product form offered every customer's brands, and let the customer of an
 * existing product be changed although everything about it belongs to that customer.
 */
it('keeps a product with its customer and its brand with that customer', function (): void {
    $this->actingAs(App\Models\User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());

    $product = Product::query()->firstOrFail();
    // A second customer, copied from the first: the test seed has only one.
    $row = (array) DB::table('customers')->where('id', $product->customer_id)->first();
    unset($row['id']);
    $otherCustomer = (int) DB::table('customers')->insertGetId([...$row, 'code' => 'CUS-T-OTHER', 'name' => 'Another customer']);

    $brand = fn (?int $customerId, string $code): int => (int) DB::table('brands')->insertGetId([
        'code' => $code, 'name' => "Brand {$code}", 'customer_id' => $customerId,
    ]);

    $payload = fn (array $changes): array => [
        ...$product->only(['customer_id', 'code', 'name', 'product_type', 'status', 'routing_id']),
        ...$changes,
    ];

    $update = fn (array $changes) => $this->put("/products/{$product->id}", $payload($changes));

    $update(['customer_id' => $otherCustomer])->assertSessionHasErrors('customer_id');
    expect($product->refresh()->customer_id)->not->toBe($otherCustomer);

    $update(['brand_id' => $brand($otherCustomer, 'BR-T-OTHER')])
        ->assertSessionHasErrors(['brand_id' => 'Choose a brand that belongs to this customer.']);

    $own = $brand($product->customer_id, 'BR-T-OWN');
    $update(['brand_id' => $own])->assertSessionHasNoErrors();
    expect($product->refresh()->brand_id)->toBe($own);

    // A brand that belongs to no single customer may go on anyone's product.
    $shared = $brand(null, 'BR-T-SHARED');
    $update(['brand_id' => $shared])->assertSessionHasNoErrors();
    expect($product->refresh()->brand_id)->toBe($shared);
});

/*
 * UX audit M-21. A draft BOM could not be corrected, and a new version always started from the
 * active one rather than the newest.
 */
it('edits a draft bill of materials, and starts a new version from the newest one', function (): void {
    $this->actingAs(App\Models\User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());

    $active = App\Modules\Product\Models\Bom::query()->where('status', 'active')->with('lines')->firstOrFail();
    $product = $active->product;
    $line = $active->lines->first();

    $lines = fn (float $qty): array => [[
        'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'qty_per_base' => $qty, 'wastage_pct' => 0,
    ]];

    $this->post("/products/{$product->id}/boms", ['base_qty' => 1000, 'lines' => $lines(7.5)])->assertSessionHasNoErrors();
    $draft = App\Modules\Product\Models\Bom::query()->where('product_id', $product->id)->orderByDesc('version_no')->firstOrFail();
    expect($draft->status)->toBe('draft');

    // A new version now opens on that draft — the newest — not on the active one.
    $this->get("/products/{$product->id}/boms/create")->assertInertia(fn ($page) => $page
        ->where('basedOn.version_no', $draft->version_no)
        ->where('activeLines.0.qty_per_base', fn ($qty) => (float) $qty === 7.5));

    // The draft is corrected in place: same version, new figure, no extra version.
    $this->get("/boms/{$draft->id}/edit")->assertOk()->assertInertia(fn ($page) => $page->where('bom.id', $draft->id));
    $this->put("/boms/{$draft->id}", ['base_qty' => 1000, 'notes' => 'typo fixed', 'lines' => $lines(7.25)])->assertSessionHasNoErrors();

    expect((float) $draft->refresh()->lines()->firstOrFail()->qty_per_base)->toBe(7.25)
        ->and($draft->lines()->count())->toBe(1)
        ->and($draft->notes)->toBe('typo fixed')
        ->and((int) $product->boms()->max('version_no'))->toBe($draft->version_no);

    // The active version is what job cards were planned from: it is not editable.
    $this->put("/boms/{$active->id}", ['base_qty' => 1000, 'lines' => $lines(1)])->assertSessionHas('error');
    $this->get("/boms/{$active->id}/edit")->assertRedirect();
    expect((float) $active->refresh()->lines->first()->qty_per_base)->toBe((float) $line->qty_per_base);

    // Saving a draft with "activate" promotes it, as creating one does.
    $this->put("/boms/{$draft->id}", ['base_qty' => 1000, 'lines' => $lines(7.25), 'activate' => true])->assertSessionHasNoErrors();
    expect($draft->refresh()->status)->toBe('active')->and($active->refresh()->status)->toBe('superseded');
});
