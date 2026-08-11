<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 7. INVENTORY
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (self::hasConstraint('purchase_return_lines_lot_fk')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE purchase_return_lines
    ADD CONSTRAINT purchase_return_lines_lot_fk FOREIGN KEY (lot_id) REFERENCES stock_lots(id)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `purchase_return_lines` DROP FOREIGN KEY `purchase_return_lines_lot_fk`');
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
