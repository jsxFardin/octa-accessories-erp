<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2. ORGANISATION & MASTER DATA
 *
 * `items` is created before `customers` — a buyer-nominated material and a customer-owned
 * tool both point at a customer — so the two links are added once customers exist, the way
 * `price_list_lines.product_id` is.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM information_schema.table_constraints
             WHERE constraint_schema = DATABASE() AND constraint_name = ?',
            ['items_customer_fk'],
        );

        if ($exists !== null) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE items
    ADD CONSTRAINT items_customer_fk   FOREIGN KEY (customer_id)            REFERENCES customers(id),
    ADD CONSTRAINT items_tool_owner_fk FOREIGN KEY (tool_owner_customer_id) REFERENCES customers(id)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE items DROP FOREIGN KEY items_customer_fk');
        DB::statement('ALTER TABLE items DROP FOREIGN KEY items_tool_owner_fk');
    }
};
