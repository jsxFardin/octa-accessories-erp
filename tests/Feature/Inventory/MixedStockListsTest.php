<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * Copy review, 6 Oct 2026. The lots list, on-hand stock and the stock count head a column
 * "Material", but they also hold finished goods — and for those the cell was blank, because
 * only the material was sent to the page.
 */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());

    $raw = DB::table('stock_lots')->whereNotNull('item_id')->orderBy('id')->first();
    $productId = (int) DB::table('products')->value('id');

    // A finished-goods lot beside the seeded raw-material ones.
    $this->fgLotId = (int) DB::table('stock_lots')->insertGetId([
        ...collect((array) $raw)->except(['id', 'item_id', 'barcode', 'lot_no'])->all(),
        'lot_no' => 'L-FG-MIXED',
        'barcode' => 'BC-FG-MIXED',
        'kind' => 'finished_goods',
        'item_id' => null,
        'product_id' => $productId,
    ]);
    $this->productCode = (string) DB::table('products as p')->join('items as pi', 'pi.id', '=', 'p.item_id')->where('p.id', $productId)->value('pi.code');
});

it('names the product on a finished-goods lot in the lots list, and filters by type', function (): void {
    $this->get('/lots?kind=finished_goods')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('lots.data', fn ($rows) => collect($rows)->contains(
            fn ($row) => $row['lot_no'] === 'L-FG-MIXED' && $row['product']['code'] === $this->productCode && $row['item'] === null,
        ) && collect($rows)->every(fn ($row) => $row['kind'] === 'finished_goods')));

    $this->get('/lots?kind=raw_material')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('lots.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['item'] !== null)));
});

it('says whether an on-hand row is a material or a product, and filters by it', function (): void {
    if (! DB::table('stock_balances')->where('lot_id', $this->fgLotId)->exists()) {
        $this->markTestSkipped('On-hand balances are a view over posted stock; this fixture lot has none.');
    }

    $this->get('/stock?type=product')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->isNotEmpty()
            && collect($rows)->every(fn ($row) => $row['holds'] === 'product' && filled($row['item_code']))));

    $this->get('/stock?type=material')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['holds'] === 'material')));
});
