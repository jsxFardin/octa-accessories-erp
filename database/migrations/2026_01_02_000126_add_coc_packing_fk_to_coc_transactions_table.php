<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 12. FINISHED GOODS, PACKING, DISPATCH, FLEET
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (self::hasConstraint('coc_packing_fk')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE coc_transactions
    ADD CONSTRAINT coc_packing_fk FOREIGN KEY (packing_list_id) REFERENCES packing_lists(id)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `coc_transactions` DROP FOREIGN KEY `coc_packing_fk`');
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
