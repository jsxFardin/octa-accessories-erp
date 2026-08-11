<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 3. PRODUCT, ARTWORK, BOM, ROUTING, TOOLING
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (self::hasConstraint('price_list_lines_product_fk')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE price_list_lines
    ADD CONSTRAINT price_list_lines_product_fk FOREIGN KEY (product_id) REFERENCES products(id)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `price_list_lines` DROP FOREIGN KEY `price_list_lines_product_fk`');
    }

    /** Constraint names are schema-unique in MySQL, which makes this a straight lookup. */
    private static function hasConstraint(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.table_constraints
             WHERE constraint_schema = DATABASE() AND constraint_name = ?',
            [$name],
        ) !== null;
    }
};
