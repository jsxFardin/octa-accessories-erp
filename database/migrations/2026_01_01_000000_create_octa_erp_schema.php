<?php

use App\Support\Schema\SqlScript;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The authoritative DDL for Octa ERP lives in docs/02a-schema.sql (02-database-schema §3).
 *
 * It is executed verbatim rather than transcribed into the schema builder for three reasons:
 * generated NULL-able key columns (§5.1) that enforce Gate 1, named CHECK constraints whose
 * names are the error message (§5.3), and the four views. Laravel's builder can express none
 * of those without raw statements anyway, and a transcription would drift from the document
 * the auditors read.
 */
return new class extends Migration
{
    public function up(): void
    {
        $script = SqlScript::fromFile(base_path('docs/02a-schema.sql'));

        foreach ($script->statements() as $statement) {
            DB::unprepared($statement);
        }
    }

    public function down(): void
    {
        $script = SqlScript::fromFile(base_path('docs/02a-schema.sql'));

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (array_reverse($script->views()) as $view) {
            DB::statement("DROP VIEW IF EXISTS `{$view}`");
        }

        foreach (array_reverse($script->tables()) as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
};
