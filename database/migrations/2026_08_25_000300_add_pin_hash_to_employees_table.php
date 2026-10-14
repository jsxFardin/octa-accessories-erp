<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 06-rbac §6 — the floor PIN becomes a secret.
 *
 * It was the last four characters of the badge number, and the badge is printed on the card
 * an operator wears on their chest. Anyone who could read it could sign in as them and book
 * their output, their waste and their downtime, which makes every production record on the
 * floor unattributable.
 *
 * No badge is backfilled with a derived PIN. A PIN the badge reveals is not a secret, and a
 * default nobody has changed is the one an attacker tries first. A badge without a PIN cannot
 * sign in until an administrator sets one under Configuration → Users; the Users list says
 * which badges still need one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('pin_hash', 255)->nullable()->after('card_no');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('pin_hash');
        });
    }
};
