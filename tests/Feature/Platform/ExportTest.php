<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Export\ExportRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
});

it('exports every registered list without erroring', function (): void {
    foreach (array_keys(ExportRegistry::all()) as $resource) {
        $response = $this->actingAs($this->admin)->get("/exports/{$resource}");

        expect($response->getStatusCode())->toBe(200, "export {$resource}");
    }
});

it('names only columns that exist', function (): void {
    // An expression naming a column that is not there produces a 500 on download — after the
    // headers have already been sent, so the user gets a truncated file rather than an error.
    $problems = [];

    foreach (ExportRegistry::all() as $key => $definition) {
        $tables = [$definition['from'], ...array_column($definition['joins'] ?? [], 0)];
        $aliases = [];

        foreach ($tables as $table) {
            [$name, $alias] = array_pad(preg_split('/\s+as\s+/i', $table), 2, null);
            $aliases[$alias ?? $name] = $name;
        }

        foreach ([...array_values($definition['columns']), ...$definition['searchable'], ...array_values($definition['filters'])] as $expression) {
            [$alias, $column] = array_pad(explode('.', $expression), 2, null);

            if ($column === null || ! isset($aliases[$alias])) {
                $problems[] = "{$key}: {$expression} has no known table";

                continue;
            }

            if (! Schema::hasColumn($aliases[$alias], $column)) {
                $problems[] = "{$key}: {$aliases[$alias]}.{$column} does not exist";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('carries the screen filters into the file', function (): void {
    $status = DB::table('sales_orders')->value('status');

    $csv = $this->actingAs($this->admin)
        ->get('/exports/sales-orders?status=nonexistent-status')
        ->streamedContent();

    // Header row only: an export that ignored the filter would return the whole order book.
    expect(substr_count(trim($csv), "\n"))->toBe(0);

    $filtered = $this->actingAs($this->admin)
        ->get("/exports/sales-orders?status={$status}")
        ->streamedContent();

    expect(substr_count(trim($filtered), "\n"))->toBeGreaterThan(0);
});

it('exports only the columns that were ticked', function (): void {
    $csv = $this->actingAs($this->admin)
        ->get('/exports/customers?columns=Code,Name')
        ->streamedContent();

    $header = trim(strtok($csv, "\n"));

    expect($header)->toContain('Code')
        ->and($header)->toContain('Name')
        ->and($header)->not->toContain('Credit limit');
});

it('writes a real workbook when xlsx is asked for', function (): void {
    $xlsx = $this->actingAs($this->admin)
        ->get('/exports/customers?format=xlsx&columns=Code,Name')
        ->streamedContent();

    // An XLSX is a zip. Anything else means the writer streamed an error page into the file.
    expect(substr($xlsx, 0, 2))->toBe('PK');

    $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    file_put_contents($path, $xlsx);

    $rows = [];

    foreach (App\Support\Import\Spreadsheet::rows($path, 'xlsx') as $row) {
        $rows[] = $row;
    }

    expect($rows[0])->toBe(['Code', 'Name'])
        ->and(count($rows))->toBeGreaterThan(1);
});

it('renders a pdf when pdf is asked for', function (): void {
    // Not streamed, unlike the other two: dompdf lays the whole table out before it writes.
    $pdf = $this->actingAs($this->admin)
        ->get('/exports/customers?format=pdf&columns=Code,Name')
        ->getContent();

    expect(substr($pdf, 0, 4))->toBe('%PDF');
});

it('refuses a format it cannot write', function (): void {
    $this->actingAs($this->admin)->get('/exports/customers?format=docx')->assertStatus(422);
});

it('refuses an export to someone who may read the list but not export it', function (): void {
    // `.export` is a permission of its own: reading the order book and carrying it out of the
    // building are different questions (06-rbac §2).
    $user = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'operator'))->firstOrFail();

    $this->actingAs($user)->get('/exports/sales-orders')->assertForbidden();
    $this->actingAs($user)->get('/exports/sales-orders/columns')->assertForbidden();
});

it('records what left the building', function (): void {
    $this->actingAs($this->admin)->get('/exports/items?columns=Code')->streamedContent();

    expect(DB::table('audit_logs')
        ->where('auditable_type', 'exports')
        ->where('event', 'exported')
        ->exists())->toBeTrue();
});
