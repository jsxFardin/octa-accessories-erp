<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs views left locked to a MySQL account that no longer exists.
 *
 * MySQL defaults a view to `SQL SECURITY DEFINER` and stamps the creating account into it, so
 * a view runs as whoever ran the migration rather than as whoever queries it. Restore a
 * database under one user, point the application at another, drop the first — a perfectly
 * ordinary sequence when a UAT box is rebuilt or a dump is moved between environments — and
 * every screen reading a view fails with:
 *
 *     SQLSTATE[HY000]: General error: 1449
 *     The user specified as a definer ('octa_accessories_erp'@'localhost') does not exist
 *
 * which points at an account instead of at the view, on a dashboard that was working
 * yesterday. The migrations now create views as `SQL SECURITY INVOKER`, which depends on no
 * account outliving the install; this brings databases created before that change into line.
 *
 * It reads each definition back from the server rather than restating it, so it repairs
 * whatever the view currently is — `v_coc_reconciliation` has been redefined once already —
 * and cannot silently revert a later migration's version of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->definerViews() as $view) {
            $definition = $this->definitionOf($view);

            // Nothing to rebuild it from. Leaving the broken view in place beats replacing it
            // with an empty one — the error at least names what is wrong.
            if ($definition === null || trim($definition) === '') {
                continue;
            }

            DB::unprepared("CREATE OR REPLACE SQL SECURITY INVOKER VIEW `{$view}` AS {$definition}");
        }
    }

    /**
     * Irreversible by choice. Rolling back means handing these views to a named account again,
     * and the only account available to name is whoever is running the rollback — which is the
     * bug, not the previous state.
     */
    public function down(): void {}

    /** @return list<string> */
    private function definerViews(): array
    {
        /** @var list<object{TABLE_NAME: string}> $rows */
        $rows = DB::select(
            'SELECT TABLE_NAME FROM information_schema.VIEWS
             WHERE TABLE_SCHEMA = DATABASE() AND SECURITY_TYPE = ? ORDER BY TABLE_NAME',
            ['DEFINER'],
        );

        return array_map(static fn (object $row): string => $row->TABLE_NAME, $rows);
    }

    private function definitionOf(string $view): ?string
    {
        /** @var object{VIEW_DEFINITION: string|null}|null $row */
        $row = DB::selectOne(
            'SELECT VIEW_DEFINITION FROM information_schema.VIEWS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$view],
        );

        return $row?->VIEW_DEFINITION;
    }
};
