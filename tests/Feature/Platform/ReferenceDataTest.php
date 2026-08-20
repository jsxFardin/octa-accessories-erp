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

// --- The directory hub -------------------------------------------------------------------

it('lists every group on Setup, without loading the rows', function (): void {
    // Twenty-five lists with their rows on one page was a slow screen that is 90% unread,
    // and it asked people to edit in two places. The hub is a directory; the list is the work.
    $this->actingAs($this->admin)->get('/setup')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Setup/Index')
            ->has('groups', count(ReferenceRegistry::GROUPS))
            ->has('groups.0.lists')
            ->missing('cards')
            ->missing('current'));
});

it('shows a group only when the user may read a list inside it', function (): void {
    $planner = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'planner'))->firstOrFail();

    // The planner holds reference_data:read but not employee.view_any, and Employees is the
    // only list in the People group — so the group disappears rather than showing an empty card.
    $keys = collect($this->actingAs($planner)->get('/setup')->viewData('page')['props']['groups'] ?? [])
        ->pluck('key');

    expect($keys)->not->toContain('people');
});

// --- Vocabularies ------------------------------------------------------------------------

it('backs every vocabulary column with a foreign key rather than a check constraint', function (): void {
    // The screen and the database have to agree on what a column accepts. They used to agree
    // by repetition — a CHECK constraint in the DDL, the same list in a PHP enum — and drifted
    // (`products.product_type` admitted 'ribbon' while the enum knew six values). A foreign
    // key removes the second copy: the vocabulary table *is* the list.
    $columns = [
        ['customers', 'kind', 'customer_kinds'],
        ['routings', 'product_type', 'product_types'],
        ['products', 'product_type', 'product_types'],
        ['products', 'status', 'product_statuses'],
        ['product_specs', 'cut_type', 'cut_types'],
        ['inquiries', 'source', 'inquiry_sources'],
        ['inquiry_lines', 'product_type', 'product_types'],
        ['sales_orders', 'priority', 'order_priorities'],
        ['defects', 'severity', 'defect_severities'],
        ['qc_inspections', 'disposition', 'qc_dispositions'],
        ['ncrs', 'severity', 'defect_severities'],
    ];

    $problems = [];

    foreach ($columns as [$table, $column, $references]) {
        $found = DB::select(
            'SELECT referenced_table_name AS target FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
               AND referenced_table_name IS NOT NULL',
            [$table, $column],
        );

        if (array_column($found, 'target') !== [$references]) {
            $problems[] = "{$table}.{$column} does not point at {$references}";
        }
    }

    expect($problems)->toBe([]);
});

it('seeds the behaviour the calculators are tested against', function (): void {
    // The unit tests state BR-9, BR-10, BR-11 and BR-13 as constants (tests/Pest.php); these
    // are the rows they stand for. Edit the seeder without the rules and this fails here
    // rather than in a cost sheet.
    foreach (PRODUCT_TYPE_FIXTURES as $code => [$yarn, $sheets, $ink, $tool]) {
        $rule = App\Support\Reference\Vocabulary::productType($code);

        expect($rule->consumesYarn())->toBe($yarn, "{$code}: consumes_yarn")
            ->and($rule->consumesSheets())->toBe($sheets, "{$code}: consumes_sheets")
            ->and($rule->defaultInkLayGsm())->toBe($ink, "{$code}: default_ink_lay_gsm")
            ->and($rule->requiresToolPerColour())->toBe($tool, "{$code}: requires_tool_per_colour");
    }

    foreach (CUT_TYPE_FIXTURES as $code => [$gap, $tool]) {
        $rule = App\Support\Reference\Vocabulary::cutType($code);

        expect($rule->defaultCutGapMm())->toBe($gap, "{$code}: default_cut_gap_mm")
            ->and($rule->requiresTool())->toBe($tool, "{$code}: requires_tool");
    }
});

it('edits a vocabulary like any other list', function (): void {
    $this->actingAs($this->admin)->post('/setup/product-types', [
        'code' => 'embroidered',
        'name' => 'Embroidered badge',
        'consumes_yarn' => true,
        'consumes_sheets' => false,
        'default_ink_lay_gsm' => null,
        'requires_tool_per_colour' => false,
        'sort_order' => 100,
        'is_active' => true,
    ])->assertRedirect();

    App\Support\Reference\Vocabulary::flush();

    expect(App\Support\Reference\Vocabulary::codes('product_type'))->toContain('embroidered')
        ->and(App\Support\Reference\Vocabulary::productType('embroidered')->consumesYarn())->toBeTrue();

    // And the column accepts it, which is the whole point of the foreign key.
    $customerId = DB::table('customers')->insertGetId([
        'code' => 'VOC-1', 'name' => 'Vocabulary customer', 'kind' => 'brand', 'created_at' => now(),
    ]);

    DB::table('products')->insert([
        'customer_id' => $customerId,
        'routing_id' => DB::table('routings')->where('code', 'RT-WOVEN')->value('id'),
        'code' => 'VOC-PRD-1',
        'name' => 'Embroidered badge',
        'product_type' => 'embroidered',
        'status' => 'development',
        'created_at' => now(),
    ]);

    expect(DB::table('products')->where('code', 'VOC-PRD-1')->exists())->toBeTrue();
});

it('still refuses a value no vocabulary row carries', function (): void {
    expect(fn () => DB::table('customers')->insert([
        'code' => 'VOC-2', 'name' => 'Unknown kind', 'kind' => 'space_agency', 'created_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('lists the vocabularies on Setup so they can be opened and edited', function (): void {
    $groups = collect($this->actingAs($this->admin)->get('/setup')->viewData('page')['props']['groups'] ?? []);
    $vocabularies = $groups->firstWhere('key', 'vocabularies');

    expect($vocabularies['lists'] ?? [])->toHaveCount(8);
});
