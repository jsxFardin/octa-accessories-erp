<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 9. MANUFACTURING
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (self::hasConstraint('stock_lots_job_fk')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE stock_lots
    ADD CONSTRAINT stock_lots_job_fk FOREIGN KEY (job_card_id) REFERENCES job_cards(id)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `stock_lots` DROP FOREIGN KEY `stock_lots_job_fk`');
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
