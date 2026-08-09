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
