<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Services\ItemMasterService;
use App\Modules\Product\Models\Routing;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Spec §2 — every production family has a standard process as a routing; shared processes
 * are one operation master used by several; optional steps are marked.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
});

it('seeds a default routing for each of the ten families, built from the operation master', function (): void {
    $families = DB::table('production_families')->pluck('id', 'code');

    foreach ($families as $code => $id) {
        $default = Routing::query()->where('production_family_id', $id)->whereNull('product_type')->where('is_default', true)->with('operations')->get();

        expect($default)->toHaveCount(1, "family {$code} has one default routing")
            ->and($default->first()->operations->every(fn ($operation): bool => $operation->operation_id !== null))->toBeTrue("family {$code} steps come from the master");
    }

    expect(Routing::query()->whereNotNull('production_family_id')->count())
        ->toBeGreaterThanOrEqual(count(ReferenceDataSeeder::familyProcesses()));
});

it('runs a shared process as the same operation on every family that uses it', function (): void {
    $dyeing = DB::table('operations')->where('code', 'DYE')->first();

    expect((bool) $dyeing->is_shared)->toBeTrue();

    $families = DB::table('routing_operations as ro')
        ->join('routings as r', 'r.id', '=', 'ro.routing_id')
        ->join('production_families as f', 'f.id', '=', 'r.production_family_id')
        ->where('ro.operation_id', $dyeing->id)
        ->distinct()
        ->pluck('f.code')
        ->sort()
        ->values()
        ->all();

    // Dyeing: narrow textile and cord. Printing: paper, packaging and the printed label.
    expect($families)->toBe(['01', '06']);
});

it('marks the optional steps of a family process', function (): void {
    $tape = Routing::query()->where('code', 'RT-01-TAPE')->with('operations')->firstOrFail();

    expect($tape->operations->firstWhere('code', 'heatset')->is_optional)->toBeTrue()
        ->and($tape->operations->firstWhere('code', 'dye')->is_optional)->toBeFalse();
});

it('gives a made cord item the cord family routing by default', function (): void {
    $family = DB::table('production_families')->where('code', '06')->first();
    $uom = DB::table('uoms')->where('code', 'pcs')->value('id') ?? DB::table('uoms')->value('id');

    $item = app(ItemMasterService::class)->create([
        'name' => 'Drawcord 5 mm',
        'item_type' => 'finished_good',
        'make_or_buy' => 'make',
        'production_family_id' => $family->id,
        'base_uom_id' => $uom,
        'order_uom_id' => $uom,
        'garment_type' => 'knit',
        'material_base' => 'textile',
    ], [], $this->admin->id);

    expect($item->product?->routing?->code)->toBe('RT-06-CORD');
});

it('creates a routing for a family from master operations, with an optional step', function (): void {
    $family = DB::table('production_families')->where('code', '04')->value('id');
    $chain = DB::table('operations')->where('code', 'CHAIN')->first();
    $qc = DB::table('operations')->where('code', 'QC')->first();

    $this->actingAs($this->admin)
        ->post('/routings', [
            'code' => 'RT-04-TEST',
            'name' => 'Zipper — test',
            'production_family_id' => $family,
            'product_type' => null,
            'operations' => [
                ['code' => 'chain', 'name' => 'Chain formation', 'operation_id' => $chain->id, 'machine_group_id' => $chain->machine_group_id, 'std_rate_per_hour' => 200, 'consumes_web' => false],
                ['code' => 'puller', 'name' => 'Puller fitting', 'is_optional' => true, 'consumes_web' => false],
                ['code' => 'qc', 'name' => 'Final inspection', 'operation_id' => $qc->id, 'requires_qc' => true, 'consumes_web' => false],
            ],
        ])
        ->assertSessionHasNoErrors();

    $routing = Routing::query()->where('code', 'RT-04-TEST')->with('operations')->firstOrFail();

    expect($routing->production_family_id)->toBe((int) $family)
        ->and($routing->product_type)->toBeNull()
        ->and($routing->operations[0]->operation_id)->toBe((int) $chain->id)
        ->and($routing->operations[1]->is_optional)->toBeTrue();

    // Neither a family nor a type is refused.
    $this->actingAs($this->admin)
        ->post('/routings', ['code' => 'RT-NONE', 'name' => 'Nowhere', 'operations' => [['code' => 'x', 'name' => 'X']]])
        ->assertSessionHasErrors(['production_family_id', 'product_type']);
});

it('refuses a routing from another family on a product, and accepts its own', function (): void {
    $product = App\Modules\Product\Models\Product::query()->with('item')->firstOrFail();
    $cord = Routing::query()->where('code', 'RT-06-CORD')->firstOrFail();

    expect($cord->fits($product->product_type, $product->item->production_family_id))->toBeFalse()
        ->and($product->routing->fits($product->product_type, $product->item->production_family_id))->toBeTrue();
});
