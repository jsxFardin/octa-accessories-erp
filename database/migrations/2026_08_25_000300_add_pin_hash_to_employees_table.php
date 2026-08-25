<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * 06-rbac §6 — the floor PIN becomes a secret.
 *
 * It was the last four characters of the badge number, and the badge is printed on the card
 * an operator wears on their chest. Anyone who could read it could sign in as them and book
 * their output, their waste and their downtime, which makes every production record on the
 * floor unattributable.
 *
 * Existing badges are backfilled with a hash of the old derived PIN so nobody is locked out of
 * a running factory mid-shift. That preserves the weakness for accounts nobody has touched —
 * the point is that a PIN can now be changed to something the badge does not reveal, and the
 * derivation is no longer the rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('pin_hash', 255)->nullable()->after('card_no');
        });

        DB::table('employees')
            ->whereNotNull('card_no')
            ->orderBy('id')
            ->each(function (object $employee): void {
                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update(['pin_hash' => Hash::make(substr((string) $employee->card_no, -4))]);
            });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('pin_hash');
        });
    }
};
