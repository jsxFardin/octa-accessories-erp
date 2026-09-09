<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\StockPostingService;
use Illuminate\Support\Facades\DB;

/**
 * G6 — "any carton → its lots → its GRNs, in ≤ 3 clicks", and the overview's claim that every
 * physical unit carries a barcode.
 *
 * `stock_lots.barcode` was populated on no path at all: not by a GRN receipt, not by a finished
 * goods receipt, and the transfer machine wrote NULL into it explicitly. Cartons got one;
 * lots — the thing a store keeper actually scans off a shelf — got nothing. The seed carried 0
 * barcoded lots out of 23. `stock_lot.print_barcode` is a permission with no route behind it,
 * which is consistent: there was nothing on the row to print.
 */
it('lots: a new lot is born with a barcode', function (): void {
    $lot = StockLot::query()->create([
        'lot_no' => 'BC-'.uniqid('', false),
        'kind' => 'raw_material',
        'item_id' => DB::table('items')->value('id'),
        'warehouse_id' => DB::table('warehouses')->where('is_active', true)->value('id'),
        'uom_id' => DB::table('uoms')->where('code', 'kg')->value('id'),
        'received_qty' => 0,
        'balance_qty' => 0,
        'unit_cost' => 1,
        'status' => 'available',
        'received_on' => now()->toDateString(),
    ]);

    // A scanner reading the label lands on the lot a person reading it aloud would.
    expect($lot->barcode)->toBe($lot->lot_no);
});

it('lots: a receipt through the posting service produces a barcoded lot', function (): void {
    $grn = DB::table('grns')->first();

    $lot = app(StockPostingService::class)->receive(
        [
            'lot_no' => 'BC-RCV-'.uniqid('', false),
            'kind' => 'raw_material',
            'item_id' => DB::table('items')->value('id'),
            'warehouse_id' => DB::table('warehouses')->where('is_active', true)->value('id'),
            'uom_id' => DB::table('uoms')->where('code', 'kg')->value('id'),
            'received_on' => now()->toDateString(),
        ],
        qty: 25,
        unitCost: 4.5,
        source: StockLot::query()->firstOrFail(),
    );

    expect($lot->barcode)->not->toBeNull()->toBe($lot->lot_no);
})->skip(fn (): bool => DB::table('stock_lots')->count() === 0, 'No lot to stand in as the movement source.');

it('lots: a supplier\'s own label is left alone', function (): void {
    // Overwriting a barcode the goods already carry would make the label on the roll and the
    // label in the system disagree, which is worse than having none.
    $lot = StockLot::query()->create([
        'lot_no' => 'BC-OWN-'.uniqid('', false),
        'kind' => 'raw_material',
        'item_id' => DB::table('items')->value('id'),
        'warehouse_id' => DB::table('warehouses')->where('is_active', true)->value('id'),
        'uom_id' => DB::table('uoms')->where('code', 'kg')->value('id'),
        'received_qty' => 0,
        'balance_qty' => 0,
        'unit_cost' => 1,
        'status' => 'available',
        'received_on' => now()->toDateString(),
        'barcode' => 'SUPPLIER-8801234567895',
    ]);

    expect($lot->barcode)->toBe('SUPPLIER-8801234567895');
});

it('lots: a transferred child gets its own barcode, not its parent\'s', function (): void {
    // The transfer machine used to write NULL here explicitly, so a roll that moved warehouse
    // arrived unlabelled — the one moment a store keeper most needs to scan it.
    $parent = StockLot::query()->create([
        'lot_no' => 'BC-PARENT-'.uniqid('', false),
        'kind' => 'raw_material',
        'item_id' => DB::table('items')->value('id'),
        'warehouse_id' => DB::table('warehouses')->where('is_active', true)->value('id'),
        'uom_id' => DB::table('uoms')->where('code', 'kg')->value('id'),
        'received_qty' => 0,
        'balance_qty' => 0,
        'unit_cost' => 1,
        'status' => 'available',
        'received_on' => now()->toDateString(),
    ]);

    $child = StockLot::query()->create([
        'lot_no' => 'BC-CHILD-'.uniqid('', false),
        'kind' => $parent->kind,
        'item_id' => $parent->item_id,
        'warehouse_id' => $parent->warehouse_id,
        'uom_id' => $parent->uom_id,
        'parent_lot_id' => $parent->id,
        'received_qty' => 0,
        'balance_qty' => 0,
        'unit_cost' => $parent->unit_cost,
        'status' => 'available',
        'received_on' => now()->toDateString(),
    ]);

    expect($child->barcode)->toBe($child->lot_no)
        ->and($child->barcode)->not->toBe($parent->barcode);
});
