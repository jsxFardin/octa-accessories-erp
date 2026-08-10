<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Supplier;
use App\Support\Import\ImportRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
});

/** A CSV on disk, because the importer reads a file rather than a string. */
function csvFile(string $contents, string $name = 'import.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';

    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

it('writes only to columns that exist', function (): void {
    // A field naming a column that is not there fails at the first row of the first import,
    // by which time somebody has already trusted the sample file.
    $problems = [];

    foreach (ImportRegistry::all() as $key => $definition) {
        $table = (new $definition['model'])->getTable();

        foreach ($definition['fields'] as $field => $spec) {
            $column = $spec['column'] ?? $field;

            if (! Schema::hasColumn($table, $column)) {
                $problems[] = "{$key}: {$table}.{$column} does not exist";
            }

            if (isset($spec['lookup']) && ! Schema::hasTable($spec['lookup']['table'])) {
                $problems[] = "{$key}: lookup table {$spec['lookup']['table']} does not exist";
            }
        }

        if (! Schema::hasColumn($table, $definition['key'])) {
            $problems[] = "{$key}: natural key {$definition['key']} does not exist";
        }
    }

    expect($problems)->toBe([]);
});

it('creates rows from a csv', function (): void {
    $file = csvFile(<<<'CSV'
    code,name,kind,credit_limit,is_active
    IMP-001,Import Test One,brand,50000,yes
    IMP-002,Import Test Two,trader,0,no
    CSV);

    $response = $this->actingAs($this->admin)->post('/imports/customers', ['file' => $file]);

    $response->assertOk()->assertJson(['created' => 2, 'updated' => 0, 'skipped' => 0]);

    $customer = Customer::query()->where('code', 'IMP-001')->firstOrFail();

    expect($customer->name)->toBe('Import Test One')
        ->and($customer->kind)->toBe('brand')
        ->and((float) $customer->credit_limit)->toBe(50000.0)
        ->and($customer->is_active)->toBeTrue()
        ->and(Customer::query()->where('code', 'IMP-002')->value('is_active'))->toBeFalse();
});

it('updates the record a code already belongs to rather than duplicating it', function (): void {
    $existing = Customer::query()->firstOrFail();

    $file = csvFile("code,name\n{$existing->code},Renamed By Import\n");

    $this->actingAs($this->admin)->post('/imports/customers', ['file' => $file])
        ->assertOk()
        ->assertJson(['created' => 0, 'updated' => 1]);

    expect(Customer::query()->where('code', $existing->code)->count())->toBe(1)
        ->and($existing->fresh()->name)->toBe('Renamed By Import')
        // A file of two columns says nothing about the other twelve, so they are left alone.
        ->and((float) $existing->fresh()->credit_limit)->toBe((float) $existing->credit_limit);
});

it('skips the bad rows and imports the rest', function (): void {
    $file = csvFile(<<<'CSV'
    code,name,kind,credit_limit
    SKIP-001,Good Row,brand,1000
    ,No Code At All,brand,1000
    SKIP-003,Bad Kind,emperor,1000
    SKIP-004,Bad Number,brand,not a number
    SKIP-005,Another Good Row,trader,"2,500"
    CSV);

    $response = $this->actingAs($this->admin)->post('/imports/customers', ['file' => $file]);

    $response->assertOk()->assertJson(['created' => 2, 'skipped' => 3, 'rows' => 5]);

    // Named with their line numbers — the header is line 1, so the first bad row is line 3.
    expect(array_column($response->json('errors'), 'row'))->toBe([3, 4, 5])
        // Thousands separators survive: a spreadsheet formats the column, the number is still there.
        ->and((float) Customer::query()->where('code', 'SKIP-005')->value('credit_limit'))->toBe(2500.0);
});

it('resolves a lookup by code or name and names the ones it cannot', function (): void {
    $currency = DB::table('currencies')->first();

    $file = csvFile(<<<CSV
    code,name,currency
    LKP-001,Matched By Code,{$currency->code}
    LKP-002,Matched By Name,{$currency->name}
    LKP-003,Matched By Nothing,ZZZ
    CSV);

    $response = $this->actingAs($this->admin)->post('/imports/customers', ['file' => $file]);

    $response->assertOk()->assertJson(['created' => 2, 'skipped' => 1]);

    expect(Customer::query()->where('code', 'LKP-001')->value('currency_id'))->toBe($currency->id)
        ->and(Customer::query()->where('code', 'LKP-002')->value('currency_id'))->toBe($currency->id)
        ->and($response->json('errors.0.messages.0'))->toContain('ZZZ');
});

it('refuses a file that is missing a required column', function (): void {
    // Refused whole, unlike a bad row: a file with no `name` column is the wrong file, not a
    // file with four hundred mistakes in it.
    $this->actingAs($this->admin)
        ->postJson('/imports/customers', ['file' => csvFile("code,kind\nX-1,brand\n")])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'The file is missing required column: name.']);
});

it('ignores columns nobody asked for', function (): void {
    // An export from the old system arrives with an id and a created_at on the end.
    $file = csvFile("id,code,name,exported_on\n99,EXTRA-1,Extra Columns,2026-01-01\n");

    $this->actingAs($this->admin)->post('/imports/suppliers', ['file' => $file])->assertOk();

    expect(Supplier::query()->where('code', 'EXTRA-1')->value('name'))->toBe('Extra Columns');
});

it('applies a default only when the record is new', function (): void {
    $this->actingAs($this->admin)
        ->post('/imports/suppliers', ['file' => csvFile("code,name\nDEF-1,Defaulted Supplier\n")])
        ->assertOk();

    $supplier = Supplier::query()->where('code', 'DEF-1')->firstOrFail();

    expect($supplier->is_active)->toBeTrue()->and($supplier->is_approved)->toBeFalse();

    $supplier->update(['is_approved' => true]);

    $this->actingAs($this->admin)
        ->post('/imports/suppliers', ['file' => csvFile("code,name\nDEF-1,Renamed Supplier\n")])
        ->assertOk();

    // The second file said nothing about approval, so the approval it was given stands.
    expect($supplier->fresh()->is_approved)->toBeTrue()
        ->and($supplier->fresh()->name)->toBe('Renamed Supplier');
});

it('serves a sample file whose headers the importer accepts', function (): void {
    $sample = $this->actingAs($this->admin)->get('/imports/customers/sample')->streamedContent();

    $file = csvFile($sample, 'sample.csv');

    $this->actingAs($this->admin)->post('/imports/customers', ['file' => $file])
        ->assertOk()
        ->assertJson(['skipped' => 0, 'created' => 1]);
});

it('documents every field it accepts', function (): void {
    $response = $this->actingAs($this->admin)->getJson('/imports/items/fields');

    $response->assertOk();

    expect($response->json('fields'))->not->toBeEmpty()
        ->and(collect($response->json('fields'))->firstWhere('name', 'code'))
        ->toMatchArray(['required' => true, 'type' => 'text']);
});

it('refuses an import to someone who may create records but not load a file of them', function (): void {
    // `.import` is a right of its own: adding one customer and overwriting four hundred are
    // different acts (06-rbac §2).
    $user = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'designer'))->firstOrFail();

    expect($user->hasPermission('customer.view_any'))->toBeTrue()
        ->and($user->hasPermission('customer.import'))->toBeFalse();

    $this->actingAs($user)->postJson('/imports/customers', ['file' => csvFile("code,name\nX,Y\n")])->assertForbidden();
    $this->actingAs($user)->getJson('/imports/customers/fields')->assertForbidden();
    $this->actingAs($user)->get('/imports/customers/sample')->assertForbidden();
});

it('records what came in', function (): void {
    $this->actingAs($this->admin)
        ->post('/imports/customers', ['file' => csvFile("code,name\nAUD-1,Audited Import\n")])
        ->assertOk();

    expect(DB::table('audit_logs')
        ->where('auditable_type', 'imports')
        ->where('event', 'imported')
        ->exists())->toBeTrue();
});

it('has nothing importable that is not exportable', function (): void {
    // The two registries are read together on every list screen; a resource the panel can
    // import but not export is a screen with one button of a pair.
    $exportable = array_keys(App\Support\Export\ExportRegistry::all());

    expect(array_diff(array_keys(ImportRegistry::all()), $exportable))->toBe([]);
});
