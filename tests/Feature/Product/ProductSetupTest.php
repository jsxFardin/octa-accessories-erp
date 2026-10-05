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

    expect(Product::query()->where('code', 'PRD-SETUP-NEW')->firstOrFail()->routing_id)->toBe($default->id);
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
