<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An import is an audit event of its own.
 *
 * `audit_logs.event` is a CHECK constraint rather than a free-text column (02-database-schema
 * §5.3), so a new kind of event is a schema change. Spreadsheet import writes `imported`
 * beside the `exported` rows: the two questions people ask of the audit log are what left the
 * building and where four hundred records suddenly came from.
 *
 * docs/02a-schema.sql carries the same list, so a database created from it needs nothing here
 * — this is for the ones that already exist.
 */
return new class extends Migration
{
    private const EVENTS = "'created','updated','deleted','restored','status_changed','printed','exported','imported'";

    private const WITHOUT_IMPORTED = "'created','updated','deleted','restored','status_changed','printed','exported'";

    public function up(): void
    {
        $this->replaceConstraint(self::EVENTS);
    }

    public function down(): void
    {
        DB::table('audit_logs')->where('event', 'imported')->delete();

        $this->replaceConstraint(self::WITHOUT_IMPORTED);
    }

    private function replaceConstraint(string $events): void
    {
        DB::statement('ALTER TABLE audit_logs DROP CHECK audit_logs_event_chk');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_chk CHECK (event IN ({$events}))");
    }
};
