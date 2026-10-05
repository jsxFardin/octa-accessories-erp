<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * UX audit H-24. The transfer and adjustment forms were handed the first 400 lots of every
 * warehouse and searched them in the browser, so past that many a lot was simply not offered.
 * The picker now asks for one warehouse at a time and sends its search to the server.
 */
beforeEach(function (): void {
    $this->keeper = User::query()->where('email', 'storemanager@octapussolution.com')->firstOrFail();

    $this->lot = DB::table('stock_lots')->where('status', 'available')->where('balance_qty', '>', 0)->first();
    $this->otherWarehouseId = (int) DB::table('warehouses')->where('is_active', true)
        ->where('id', '!=', $this->lot->warehouse_id)->where('kind', '!=', 'transit')->value('id');
});

it('offers only the lots of the warehouse asked for', function (string $url): void {
    $this->actingAs($this->keeper)->get("{$url}?warehouse={$this->lot->warehouse_id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lots', fn ($lots) => collect($lots)->isNotEmpty()
                && collect($lots)->every(fn ($lot) => (int) $lot['warehouse_id'] === (int) $this->lot->warehouse_id))
            ->has('lotsMeta.total'));
})->with(['/stock-transfers/create', '/stock-adjustments/create']);

it('finds a lot by part of its number on the server', function (string $url): void {
    $fragment = substr((string) $this->lot->lot_no, -5);

    $this->actingAs($this->keeper)->get("{$url}?warehouse={$this->lot->warehouse_id}&lot_search={$fragment}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lots', fn ($lots) => collect($lots)->contains(fn ($lot) => (int) $lot['id'] === (int) $this->lot->id)));

    $this->actingAs($this->keeper)->get("{$url}?warehouse={$this->lot->warehouse_id}&lot_search=no-such-lot-zzz")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lots', [])->where('lotsMeta.total', 0));
})->with(['/stock-transfers/create', '/stock-adjustments/create']);

it('keeps a lot already on the document in the list whatever is searched for', function (): void {
    $this->actingAs($this->keeper)
        ->get("/stock-adjustments/create?warehouse={$this->lot->warehouse_id}&lot_search=no-such-lot-zzz&keep[]={$this->lot->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lots', fn ($lots) => collect($lots)->pluck('id')->map(fn ($id) => (int) $id)->all() === [(int) $this->lot->id]));
});

/*
 * UX audit H-25. Lots were called barcoded, and nothing printed a barcode or read one.
 */
it('prints a scannable label for a lot', function (): void {
    $this->actingAs($this->keeper)->get("/lots/{$this->lot->id}/label")
        ->assertOk()
        ->assertSee((string) $this->lot->lot_no)
        ->assertSee('<svg', false)
        ->assertSee('Print labels');
});

it('prints a label for every lot of a posted goods receipt, and none for one with no lots', function (): void {
    $grnId = (int) DB::table('grn_lines as gl')->join('stock_lots as sl', 'sl.grn_line_id', '=', 'gl.id')->value('gl.grn_id');
    $lots = DB::table('stock_lots as sl')->join('grn_lines as gl', 'gl.id', '=', 'sl.grn_line_id')
        ->where('gl.grn_id', $grnId)->pluck('sl.lot_no');

    $response = $this->actingAs($this->keeper)->get("/grns/{$grnId}/labels")->assertOk();

    $lots->each(fn ($lotNo) => $response->assertSee((string) $lotNo));
});

it('finds a lot by the exact code on its label', function (): void {
    DB::table('stock_lots')->where('id', $this->lot->id)->update(['barcode' => 'SCAN-ME-0001']);

    $this->actingAs($this->keeper)
        ->get("/stock-transfers/create?warehouse={$this->lot->warehouse_id}&lot_search=SCAN-ME-0001")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lots', fn ($lots) => collect($lots)->contains(
                fn ($lot) => (int) $lot['id'] === (int) $this->lot->id && $lot['barcode'] === 'SCAN-ME-0001',
            )));
});
