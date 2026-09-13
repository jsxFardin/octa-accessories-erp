<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much of this invoice line has come back, so the returnable balance is a column rather
 * than a scan of every return ever raised.
 *
 * The same shape `sales_order_lines` already uses for `produced_qty`, `delivered_qty` and
 * `invoiced_qty`: a running quantity maintained by the document that causes it, inside that
 * document's own transaction. Here the causing event is a sales return reaching `posted`.
 *
 * It is a cache, and the guard treats it as one. `SalesReturnStateMachine` re-derives the
 * returned total from `sales_return_lines` under a row lock before it allows a return through,
 * exactly as the credit note recomputes eligibility under the invoice lock instead of trusting
 * an amount computed a moment earlier. The column is what screens read; the sum is what the
 * rule is decided on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sales_invoice_lines', 'returned_qty')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE sales_invoice_lines
    ADD COLUMN returned_qty DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER qty,
    ADD CONSTRAINT sales_invoice_lines_returned_chk CHECK (returned_qty >= 0)
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sales_invoice_lines', 'returned_qty')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE sales_invoice_lines
    DROP CHECK sales_invoice_lines_returned_chk,
    DROP COLUMN returned_qty
SQL);
    }
};
