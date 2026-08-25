<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P2-1 — a written-off invoice is not a collected one.
 *
 * An invoice settled entirely by credit notes reported as `paid` with `received_amount = 0`.
 * Receivables counted it as money in, cash forecasting was wrong by its value, and a quality
 * write-off was indistinguishable from a customer who paid on time.
 *
 * `credited` is the honest state for it. Existing rows that were settled by credit alone are
 * moved across.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales_invoices DROP CHECK sales_invoices_status_chk');
        DB::statement("ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_status_chk
            CHECK (status IN ('draft','issued','partially_paid','paid','credited','overdue','cancelled'))");

        DB::table('sales_invoices')
            ->where('status', 'paid')
            ->where('received_amount', '<=', 0.0001)
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('credit_notes')
                ->whereColumn('credit_notes.sales_invoice_id', 'sales_invoices.id')
                ->where('credit_notes.status', 'applied'))
            ->update(['status' => 'credited']);
    }

    public function down(): void
    {
        DB::table('sales_invoices')->where('status', 'credited')->update(['status' => 'paid']);

        DB::statement('ALTER TABLE sales_invoices DROP CHECK sales_invoices_status_chk');
        DB::statement("ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_status_chk
            CHECK (status IN ('draft','issued','partially_paid','paid','overdue','cancelled'))");
    }
};
