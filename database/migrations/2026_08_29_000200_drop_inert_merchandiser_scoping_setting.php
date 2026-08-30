<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes `merchandiser_sees_own_only`, a switch that looked like a security control and was
 * not one.
 *
 * 06-rbac §4 lists it as an optional row-level scope: "when on, filters by `merchandiser_id`".
 * `merchandiser_id` is written onto inquiries and quotations, and no query has ever read it.
 * Authorisation in this application is permission-only — `Gate::before` → `hasPermission` — with
 * no global query scopes at all, so turning the switch on changed precisely nothing while
 * telling an administrator that merchandisers could no longer see each other's customers.
 *
 * A toggle that claims to restrict visibility and does not is worse than no toggle: it is
 * believed. Removing it is the honest smaller change; implementing partial row scoping across
 * inquiries, quotations, orders and customers is a real feature, and half of it — some screens
 * filtered, others not — would be more dangerous than none.
 *
 * The intent is preserved in 06-rbac §4, which now states what is and is not implemented.
 * Nothing else reads this key, so dropping the row removes it from the settings screen (an
 * uncatalogued key still renders there, so deleting the catalogue entry alone would have left a
 * bare auto-labelled switch behind).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'merchandiser_sees_own_only')->delete();
    }

    public function down(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'merchandiser_sees_own_only'],
            [
                'value' => json_encode(false),
                'group_name' => 'scoping',
                'description' => 'When on, a merchandiser sees only their own records (06-rbac §4)',
            ],
        );
    }
};
