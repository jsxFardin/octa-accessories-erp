<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `refunded` — a credit note whose value went back to the customer as money.
 *
 * `applied` already says a note is spent, but not how. Spending it against other invoices and
 * paying it back in cash are different events with different consequences: one moves a
 * receivable, the other moves the bank. Reporting both as `applied` would make a refund
 * invisible in exactly the place someone looks for it, which is the same reasoning that gave
 * `credited` its own status rather than letting a written-off invoice read as `paid` (P2-1).
 *
 * A note that is partly applied and partly refunded settles as `refunded`: cash left the
 * business, and that is the stronger statement of the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE credit_notes DROP CHECK credit_notes_status_chk');
        DB::statement("ALTER TABLE credit_notes ADD CONSTRAINT credit_notes_status_chk
            CHECK (status IN ('draft','approved','applied','refunded','cancelled'))");
    }

    public function down(): void
    {
        DB::table('credit_notes')->where('status', 'refunded')->update(['status' => 'applied']);

        DB::statement('ALTER TABLE credit_notes DROP CHECK credit_notes_status_chk');
        DB::statement("ALTER TABLE credit_notes ADD CONSTRAINT credit_notes_status_chk
            CHECK (status IN ('draft','approved','applied','cancelled'))");
    }
};
