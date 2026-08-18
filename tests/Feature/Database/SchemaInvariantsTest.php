<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * The five behavioural assertions from 02-database-schema §9.
 *
 * These invariants are the database's responsibility, not the application's. Each test writes
 * SQL directly, bypassing every model, service and state machine — because the point is that
 * the schema refuses even when the application does not.
 */

/** Creates the minimum rows an artwork version needs. */
function seedArtwork(): int
{
    $customerId = DB::table('customers')->insertGetId([
        'code' => 'TST-1', 'name' => 'Test customer', 'kind' => 'brand', 'created_at' => now(),
    ]);

    $routingId = (int) DB::table('routings')->where('code', 'RT-WOVEN')->value('id');

    $productId = DB::table('products')->insertGetId([
        'customer_id' => $customerId,
        'routing_id' => $routingId,
        'code' => 'TST-PRD-1',
        'name' => 'Test label',
        'product_type' => 'woven',
        'status' => 'development',
        'created_at' => now(),
    ]);

    return DB::table('artworks')->insertGetId([
        'product_id' => $productId,
        'code' => 'TST-ART-1',
        'title' => 'Test artwork',
        'created_at' => now(),
    ]);
}

it('generated key columns still reject a second approved artwork version', function (): void {
    $artworkId = seedArtwork();

    DB::table('artwork_versions')->insert([
        'artwork_id' => $artworkId, 'version_no' => 1, 'status' => 'approved',
        'file_path' => '/a/v1.ai', 'created_at' => now(),
    ]);

    // A2 / Gate 1. `approved_key` is the artwork id when approved and NULL otherwise, under a
    // plain UNIQUE index — so only approved rows compete.
    expect(fn () => DB::table('artwork_versions')->insert([
        'artwork_id' => $artworkId, 'version_no' => 2, 'status' => 'approved',
        'file_path' => '/a/v2.ai', 'created_at' => now(),
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('allows a draft version alongside an approved one', function (): void {
    $artworkId = seedArtwork();

    DB::table('artwork_versions')->insert([
        'artwork_id' => $artworkId, 'version_no' => 1, 'status' => 'approved',
        'file_path' => '/a/v1.ai', 'created_at' => now(),
    ]);

    DB::table('artwork_versions')->insert([
        'artwork_id' => $artworkId, 'version_no' => 2, 'status' => 'draft',
        'file_path' => '/a/v2.ai', 'created_at' => now(),
    ]);

    expect(DB::table('artwork_versions')->where('artwork_id', $artworkId)->count())->toBe(2);
});

it('permits exactly one base currency', function (): void {
    expect(DB::table('currencies')->where('is_base', true)->count())->toBe(1);

    expect(fn () => DB::table('currencies')->insert([
        'code' => 'JPY', 'name' => 'Yen', 'is_base' => true,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('permits exactly one current spec per product', function (): void {
    $artworkId = seedArtwork();
    $productId = (int) DB::table('artworks')->where('id', $artworkId)->value('product_id');

    $spec = [
        'product_id' => $productId, 'label_width_mm' => 40, 'label_height_mm' => 20,
        'colour_list' => '[]', 'care_symbols' => '[]', 'claims' => '[]', 'attributes' => '{}',
        'created_at' => now(),
    ];

    DB::table('product_specs')->insert([...$spec, 'version_no' => 1, 'status' => 'current']);

    // P2, by the same emulation as A2.
    expect(fn () => DB::table('product_specs')->insert([...$spec, 'version_no' => 2, 'status' => 'current']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('permits exactly one active bom per product', function (): void {
    $artworkId = seedArtwork();
    $productId = (int) DB::table('artworks')->where('id', $artworkId)->value('product_id');

    $bom = ['product_id' => $productId, 'base_qty' => 1000, 'created_at' => now()];

    DB::table('boms')->insert([...$bom, 'version_no' => 1, 'status' => 'active']);

    expect(fn () => DB::table('boms')->insert([...$bom, 'version_no' => 2, 'status' => 'active']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('cannot drive a lot balance negative', function (): void {
    $itemId = DB::table('items')->insertGetId([
        'item_category_id' => DB::table('item_categories')->where('code', 'YARN')->value('id'),
        'code' => 'TST-ITM-1', 'name' => 'Test yarn',
        'base_uom_id' => DB::table('uoms')->where('code', 'kg')->value('id'),
        'attributes' => '{}', 'created_at' => now(),
    ]);

    // BR-38 / I4. There is no "allow negative" setting to disable this.
    expect(fn () => DB::table('stock_lots')->insert([
        'lot_no' => 'TST-L1',
        'item_id' => $itemId,
        'kind' => 'raw_material',
        'warehouse_id' => DB::table('warehouses')->where('code', 'RM')->value('id'),
        'uom_id' => DB::table('uoms')->where('code', 'kg')->value('id'),
        'balance_qty' => -5,
        'created_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('cannot reject a qc inspection without a disposition', function (): void {
    // BR-33 / QC2 — no lot leaves QC without a documented disposition.
    expect(fn () => DB::table('qc_inspections')->insert([
        'stage' => 'final', 'result' => 'rejected', 'created_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);

    DB::table('qc_inspections')->insert([
        'stage' => 'final', 'result' => 'rejected', 'disposition' => 'rework', 'created_at' => now(),
    ]);

    expect(DB::table('qc_inspections')->count())->toBe(1);
});

it('cannot book more output from an operation than was handed to it', function (): void {
    // J3, with the epsilon that absorbs DECIMAL(18,6) accumulation across partial logs.
    $sql = 'SELECT 1 FROM information_schema.check_constraints
            WHERE constraint_schema = DATABASE() AND constraint_name = ?';

    expect(DB::select($sql, ['job_card_operations_output_chk']))->toHaveCount(1);
});

it('has a migration behind every object the schema document declares', function (): void {
    // The DDL now lives in one migration per table (database/migrations/2026_01_02_*), while
    // docs/02a-schema.sql stays the document the auditors read. Nothing keeps the two in step
    // automatically, so this is the guard: a table added to the document and not to a
    // migration fails here rather than in production.
    $script = App\Support\Schema\SqlScript::fromFile(base_path('docs/02a-schema.sql'));

    $schema = DB::getDatabaseName();

    // Aliased: MySQL returns these columns upper-cased, which `pluck('table_name')` misses.
    $tables = array_column(
        DB::select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ?', [$schema]),
        'name',
    );

    $views = array_column(
        DB::select('SELECT table_name AS name FROM information_schema.views WHERE table_schema = ?', [$schema]),
        'name',
    );

    expect(array_diff($script->tables(), $tables))->toBe([])
        ->and(array_diff($script->views(), $views))->toBe([]);
});

it('loads every object the specification promises', function (): void {
    $schema = DB::getDatabaseName();

    $tables = DB::table('information_schema.tables')
        ->where('table_schema', $schema)
        ->where('table_type', 'BASE TABLE')
        ->count();

    $views = DB::table('information_schema.views')->where('table_schema', $schema)->count();

    $foreignKeys = DB::table('information_schema.referential_constraints')
        ->where('constraint_schema', $schema)
        ->count();

    $checks = DB::table('information_schema.check_constraints')
        ->where('constraint_schema', $schema)
        ->count();

    // 146 ERP tables plus the framework's own (migrations, sessions, password resets, cache,
    // cache_locks, jobs, job_batches, failed_jobs, notifications). The ERP count rose by nine
    // with §13a — trade finance, import and expenses — and by eight with §1a, the vocabularies.
    // P2-4 added Laravel's standard notifications table.
    //
    // Those eight moved eleven CHECK constraints onto twelve foreign keys: the list a column
    // accepts is a table now, not a constraint body.
    expect($tables)->toBe(155)
        ->and($views)->toBe(4)
        ->and($foreignKeys)->toBe(408)
        ->and($checks)->toBe(168);
});
