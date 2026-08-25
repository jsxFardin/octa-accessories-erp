<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Conversion is an audit event of its own.
 *
 * A quotation becoming a sales order is neither an update to the quotation nor a status
 * change on it — the quotation is untouched; a different document came into existence
 * because of it. Recording that as `updated` buried the one row an auditor asks for
 * ("where did SO-26-00028 come from?") among rate edits.
 *
 * `audit_logs.event` is a CHECK constraint rather than free text (02-database-schema §5.3),
 * so a new kind of event is a schema change — same shape as the `imported` migration.
 *
 * docs/02a-schema.sql carries the same list, so a database created from it needs nothing
 * here; this is for the ones that already exist.
 */
return new class extends Migration
{
    private const EVENTS = "'created','updated','deleted','restored','status_changed','converted','printed','exported','imported'";

    private const WITHOUT_CONVERTED = "'created','updated','deleted','restored','status_changed','printed','exported','imported'";

    public function up(): void
    {
        $this->replaceConstraint(self::EVENTS);
    }

    public function down(): void
    {
        DB::table('audit_logs')->where('event', 'converted')->delete();

        $this->replaceConstraint(self::WITHOUT_CONVERTED);
    }

    private function replaceConstraint(string $events): void
    {
        DB::statement('ALTER TABLE audit_logs DROP CHECK audit_logs_event_chk');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_chk CHECK (event IN ({$events}))");
    }
};
