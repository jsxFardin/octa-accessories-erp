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
