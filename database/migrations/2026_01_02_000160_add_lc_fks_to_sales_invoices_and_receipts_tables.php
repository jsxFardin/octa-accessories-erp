<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 16. TRADE — export side
 *
 * An invoice draws on an export credit and a receipt realises one. Both columns live in their
 * base tables; the foreign keys wait here because `letters_of_credit` is created after them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (self::hasConstraint('sales_invoices_lc_fk')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE sales_invoices
    ADD KEY sales_invoices_lc_idx (lc_id),
    ADD CONSTRAINT sales_invoices_lc_fk FOREIGN KEY (lc_id) REFERENCES letters_of_credit(id)
SQL);
        DB::unprepared(<<<'SQL'
ALTER TABLE receipts
    ADD KEY receipts_lc_idx (lc_id),
    ADD CONSTRAINT receipts_lc_fk FOREIGN KEY (lc_id) REFERENCES letters_of_credit(id)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `sales_invoices` DROP FOREIGN KEY `sales_invoices_lc_fk`');
        DB::statement('ALTER TABLE `receipts` DROP FOREIGN KEY `receipts_lc_fk`');
    }

    private static function hasConstraint(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.table_constraints
             WHERE constraint_schema = DATABASE() AND constraint_name = ?',
            [$name],
        ) !== null;
    }
};
