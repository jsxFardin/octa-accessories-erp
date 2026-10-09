<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Services\JobCardReleaseGate;
use App\Modules\MasterData\Services\ItemMasterService;
use Illuminate\Support\Facades\DB;

/**
 * Spec §1–2 — a family other than the label ones makes its goods: a job card for a drawcord
 * needs no artwork and no label geometry, runs in the item's own unit, and can be raised for
 * stock or for the job that needs it as a component.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->unit = DB::table('factory_units')->value('id');
});

function madeItemIn(string $family, array $overrides = []): App\Modules\MasterData\Models\Item
{
    $familyId = DB::table('production_families')->where('code', $family)->value('id');
    $uom = DB::table('uoms')->where('code', 'pcs')->value('id') ?? DB::table('uoms')->value('id');

    $item = app(ItemMasterService::class)->create([
        'name' => "Test item {$family}",
        'item_type' => 'finished_good',
        'make_or_buy' => 'make',
        'production_family_id' => $familyId,
        'base_uom_id' => $uom,
        'order_uom_id' => $uom,
        'garment_type' => 'knit',
        'material_base' => 'textile',
        ...$overrides,
    ], [], test()->admin->id);

    // Straight to active: what the activation checklist asks is covered elsewhere.
    DB::table('items')->where('id', $item->id)->update(['status' => 'active']);

    return $item->fresh();
}

it('raises a card for stock for a cord item: no artwork, no spec, steps planned with wastage', function (): void {
    $item = madeItemIn('06');

    $this->actingAs($this->admin)
        ->post('/job-cards', [
            'product_id' => $item->product->id,
            'factory_unit_id' => $this->unit,
            'planned_qty' => 1000,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $card = JobCard::query()->where('product_id', $item->product->id)->with('operations')->firstOrFail();

    expect($card->for_stock)->toBeTrue()
        ->and($card->sales_order_line_id)->toBeNull()
        ->and($card->artwork_version_id)->toBeNull()
        ->and($card->product_spec_id)->toBeNull()
        ->and($card->gross_metres)->toBeNull()
        ->and($card->operations)->toHaveCount(6)
        ->and((float) $card->operations->last()->planned_qty)->toBe(1010.0)       // 1,000 + 1 % at the last step
        ->and((float) $card->operations->first()->planned_qty)->toBeGreaterThan(1050.0); // six steps of 1 % compounded

    // The release gate does not ask this family for artwork, and counts in its own unit.
    $gate = app(JobCardReleaseGate::class)->evaluate($card);

    expect($gate['checks']['artwork']['ok'])->toBeTrue()
        ->and($gate['checks']['artwork']['detail'])->toContain('without artwork')
        ->and($card->operations->first()->unit())->toBe('pcs');
});

it('refuses a card for a family that needs artwork until an approved version exists', function (): void {
    $item = madeItemIn('03');

    $this->actingAs($this->admin)
        ->from('/job-cards/create')
        ->post('/job-cards', [
            'product_id' => $item->product->id,
            'factory_unit_id' => $this->unit,
            'planned_qty' => 500,
        ])
        ->assertSessionHasErrors('product_id');

    expect(JobCard::query()->where('product_id', $item->product->id)->exists())->toBeFalse();
});

it('raises a component card for a parent job, and the form offers the made products', function (): void {
    $parent = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $item = madeItemIn('07');

    $this->actingAs($this->admin)
        ->get("/job-cards/create?product={$item->product->id}&qty=250&parent={$parent->id}")
        ->assertOk()
        ->assertInertia(function ($page) use ($item, $parent): void {
            $props = $page->toArray()['props'];

            expect($props['mode'])->toBe('stock')
                ->and($props['preselectProductId'])->toBe($item->product->id)
                ->and((float) $props['preselectQty'])->toBe(250.0)
                ->and($props['parent']['id'])->toBe($parent->id)
                ->and(collect($props['products'])->pluck('id')->all())->toContain($item->product->id);
        });

    $this->actingAs($this->admin)
        ->post('/job-cards', [
            'product_id' => $item->product->id,
            'parent_job_card_id' => $parent->id,
            'factory_unit_id' => $this->unit,
            'planned_qty' => 250,
        ])
        ->assertSessionHasNoErrors();

    $card = JobCard::query()->where('product_id', $item->product->id)->firstOrFail();

    expect($card->parent_job_card_id)->toBe($parent->id)
        ->and($card->for_stock)->toBeFalse();

    $this->actingAs($this->admin)->get("/job-cards/{$card->id}")->assertOk();
});

it('still refuses a card with neither an order line nor a product', function (): void {
    $this->actingAs($this->admin)
        ->from('/job-cards/create')
        ->post('/job-cards', ['factory_unit_id' => $this->unit, 'planned_qty' => 10])
        ->assertSessionHasErrors(['sales_order_line_id', 'product_id']);
});
