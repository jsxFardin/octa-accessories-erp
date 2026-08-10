<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Reference\ReferenceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
});

// --- The definitions have to match the database ------------------------------------------

it('defines only columns that exist, and never a generated one', function (): void {
    // A field naming a column the table does not have is a screen that saves nothing; a field
    // naming a generated column (`item_key`, `base_key`) is one the database rejects outright.
    $problems = [];

    foreach (ReferenceRegistry::all() as $slug => $definition) {
        $generated = collect(DB::select(
            'SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND extra LIKE "%GENERATED%"',
            [$definition['table']],
        ))->map(fn (object $row): string => (string) ($row->column_name ?? $row->COLUMN_NAME))->all();

        foreach ($definition['fields'] as $field) {
            if (! Schema::hasColumn($definition['table'], $field['name'])) {
                $problems[] = "{$slug}.{$field['name']} — no such column";
            }

            if (in_array($field['name'], $generated, true)) {
                $problems[] = "{$slug}.{$field['name']} — generated column";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('offers only values the CHECK constraints accept', function (): void {
    // A dropdown option the schema refuses is a 500 the user cannot act on.
    $schema = (string) file_get_contents(base_path('docs/02a-schema.sql'));
    $problems = [];

    foreach (ReferenceRegistry::all() as $slug => $definition) {
        // Scoped to this table's own CREATE TABLE block — `kind` is a column name on four
        // different tables, and matching the first CHECK in the file compares the wrong one.
        preg_match(
            '/CREATE TABLE '.preg_quote($definition['table'], '/').'\s*\((.*?)\n\)\s*ENGINE/s',
            $schema,
            $block,
        );

        $ddl = $block[1] ?? '';

        foreach ($definition['fields'] as $field) {
            if ($field['type'] !== 'select') {
                continue;
            }

            preg_match(
                '/CHECK \([^)]*\b'.preg_quote($field['name'], '/').'\b[^)]*IN \(([^)]*)\)/i',
                $ddl,
                $matches,
            );

            if ($matches === []) {
                continue;   // no CHECK on this column — nothing to contradict
            }

            preg_match_all("/'([^']+)'/", $matches[1], $allowed);

            foreach ($field['options'] as $option) {
                if (! in_array($option, $allowed[1], true)) {
                    $problems[] = "{$slug}.{$field['name']} offers '{$option}', which the constraint refuses";
                }
            }
        }
    }

    expect($problems)->toBe([]);
});

it('opens every list without erroring', function (): void {
    foreach (array_keys(ReferenceRegistry::all()) as $slug) {
        $this->actingAs($this->admin)->get("/setup/{$slug}")->assertOk();
    }

    $this->actingAs($this->admin)->get('/setup')->assertOk();
});

// --- Behaviour ---------------------------------------------------------------------------

it('creates, edits and deletes a lookup row', function (): void {
    $unit = DB::table('factory_units')->value('id');

    $this->actingAs($this->admin)->post('/setup/departments', [
        'factory_unit_id' => $unit,
        'code' => 'TESTDEPT',
        'name' => 'Test department',
        'kind' => 'weaving',
    ])->assertRedirect();

    $id = DB::table('departments')->where('code', 'TESTDEPT')->value('id');

    expect($id)->not->toBeNull();

    $this->actingAs($this->admin)->put("/setup/departments/{$id}", [
        'factory_unit_id' => $unit,
        'code' => 'TESTDEPT',
        'name' => 'Renamed department',
        'kind' => 'printing',
    ])->assertRedirect();

    expect(DB::table('departments')->where('id', $id)->value('name'))->toBe('Renamed department');

    $this->actingAs($this->admin)->delete("/setup/departments/{$id}")->assertRedirect();

    expect(DB::table('departments')->where('id', $id)->exists())->toBeFalse();
});

it('records every lookup edit in the audit log', function (): void {
    // These tables have no Eloquent model, which is exactly why the audit row is easy to
    // forget — a changed tax rate has to leave a trace like anything else.
    $this->actingAs($this->admin)->post('/setup/taxes', [
        'code' => 'TESTVAT',
        'name' => 'Test VAT',
        'rate_pct' => 7.5,
        'kind' => 'vat',
        'is_active' => true,
    ])->assertRedirect();

    $id = DB::table('taxes')->where('code', 'TESTVAT')->value('id');

    expect(DB::table('audit_logs')
        ->where('auditable_type', 'taxes')
        ->where('auditable_id', $id)
        ->where('event', 'created')
        ->exists())->toBeTrue();
});

it('refuses to delete a lookup something still points at', function (): void {
    // The foreign key is the authority on what is in use; the screen only has to translate it.
    $inUse = DB::table('warehouses')
        ->whereIn('id', DB::table('stock_lots')->distinct()->pluck('warehouse_id'))
        ->value('id');

    expect($inUse)->not->toBeNull();

    $this->actingAs($this->admin)
        ->delete("/setup/warehouses/{$inUse}")
        ->assertSessionHas('error');

    expect(DB::table('warehouses')->where('id', $inUse)->exists())->toBeTrue();
});

it('rejects a value outside the allowed vocabulary', function (): void {
    $this->actingAs($this->admin)->post('/setup/defects', [
        'code' => 'BADSEV',
        'name' => 'Bad severity',
        'severity' => 'catastrophic',
        'process' => 'weaving',
        'is_active' => true,
    ])->assertSessionHasErrors('severity');
});

it('keeps a lookup out of reach of a user without the permission', function (): void {
    $operator = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'operator'))->firstOrFail();

    $this->actingAs($operator)->get('/setup/departments')->assertForbidden();
    $this->actingAs($operator)->post('/setup/departments', [
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'code' => 'SNEAK',
        'name' => 'Sneaked in',
        'kind' => 'admin',
    ])->assertForbidden();

    expect(DB::table('departments')->where('code', 'SNEAK')->exists())->toBeFalse();
});

it('lets a manager read the lists without being able to change them', function (): void {
    $planner = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'planner'))->firstOrFail();

    $this->actingAs($planner)->get('/setup/departments')->assertOk();
    $this->actingAs($planner)->post('/setup/departments', [
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'code' => 'PLANNER',
        'name' => 'Planner made this',
        'kind' => 'admin',
    ])->assertForbidden();
});

// --- The tabbed hub ----------------------------------------------------------------------

it('serves one tab per group, with only that tab loaded', function (): void {
    // Twenty-five lists with their rows and reference options in one payload would be a slow
    // screen that is 90% unread.
    $this->actingAs($this->admin)->get('/setup?tab=quality')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Setup/Index')
            ->where('current', 'quality')
            // One tab per group, plus the read-only vocabularies tab.
            ->has('tabs', count(ReferenceRegistry::GROUPS) + 1)
            ->has('cards', 4)                       // defects, AQL plans, certifications, scopes
            ->has('cards.0.rows')
            ->has('cards.0.fields')
            ->has('cards.0.can'));
});

it('falls back to the first tab a user may actually open', function (): void {
    $this->actingAs($this->admin)->get('/setup?tab=nonsense')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('current', 'organisation'));
});

it('shows a card only to a user who may read that list', function (): void {
    $planner = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'planner'))->firstOrFail();

    // The planner holds reference_data:read but not employee.view_any, and Employees is the
    // only list in the People group — so the group disappears rather than showing an empty card.
    $tabs = collect($this->actingAs($planner)->get('/setup')->viewData('page')['props']['tabs'] ?? [])
        ->pluck('key');

    expect($tabs)->not->toContain('people');
});

// --- Fixed vocabularies ------------------------------------------------------------------

it('lists a vocabulary the database would actually accept', function (): void {
    // The point of the registry is that the screen and the constraint agree. An option the
    // CHECK refuses is a 500; a value the CHECK allows but the registry omits is a row nobody
    // can read back — which is exactly how `products.product_type` came to admit 'ribbon',
    // 'tape' and 'other' while the PHP enum knew six values and threw on the rest.
    $schema = (string) file_get_contents(base_path('docs/02a-schema.sql'));
    $problems = [];

    $columns = [
        'product_type' => ['products', 'product_type'],
        'cut_type' => ['product_specs', 'cut_type'],
        'customer_kind' => ['customers', 'kind'],
        'order_priority' => ['sales_orders', 'priority'],
        'product_status' => ['products', 'status'],
        'defect_severity' => ['defects', 'severity'],
        'qc_disposition' => ['qc_inspections', 'disposition'],
    ];

    foreach ($columns as $key => [$table, $column]) {
        preg_match(
            '/CREATE TABLE '.preg_quote($table, '/').'\s*\((.*?)\n\)\s*ENGINE/s',
            $schema,
            $block,
        );

        preg_match(
            '/CHECK \([^)]*\b'.preg_quote($column, '/').'\b[^)]*IN \(([^)]*)\)/i',
            $block[1] ?? '',
            $matches,
        );

        expect($matches)->not->toBe([], "no CHECK found for {$table}.{$column}");

        preg_match_all("/'([^']+)'/", $matches[1], $allowed);

        $offered = array_keys(App\Support\Reference\Vocabulary::values($key));

        foreach (array_diff($offered, $allowed[1]) as $extra) {
            $problems[] = "{$key} offers '{$extra}', which {$table}.{$column} refuses";
        }

        foreach (array_diff($allowed[1], $offered) as $missing) {
            $problems[] = "{$table}.{$column} allows '{$missing}', which {$key} does not list";
        }
    }

    expect($problems)->toBe([]);
});

it('reads back a product of every type the schema allows', function (): void {
    // `ProductType::from()` runs on every spec read. A legal row it cannot parse is a 500.
    foreach (array_keys(App\Support\Reference\Vocabulary::values('product_type')) as $value) {
        expect(App\Support\Calculators\ProductType::from($value)->label())->toBeString();
    }
});

it('shows the fixed vocabularies on their own tab', function (): void {
    $this->actingAs($this->admin)->get('/setup?tab=vocabularies')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('current', 'vocabularies')
            ->has('vocabularies', 7)
            ->has('vocabularies.0.why_fixed')
            ->where('cards', []));
});
