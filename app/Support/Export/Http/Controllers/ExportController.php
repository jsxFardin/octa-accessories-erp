<?php

declare(strict_types=1);

namespace App\Support\Export\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Export\ExportRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export for every list, from one place, in three formats.
 *
 * CSV for another system to read, XLSX for the people who were opening the CSV in Excel
 * anyway and lost the leading zeros off every code, PDF for the copy that gets printed and
 * signed. One query, one permission and one audit row behind all three.
 *
 * Two properties matter more than the file format:
 *
 *  1. **What downloads is what is on screen.** The same `q`, filter and `sort` parameters the
 *     list was showing are applied here, so an export never quietly widens the selection a
 *     user thought they had made.
 *  2. **It is a permission of its own.** `.export` is separate from `.view_any` in the
 *     catalogue (06-rbac §2): reading the order book at a desk and carrying it out of the
 *     building are different questions, and the export is written to the audit log either way.
 *
 * Streamed rather than assembled: a year of stock ledger is not going in memory.
 */
class ExportController extends Controller
{
    /** Hard ceiling. An export is a spreadsheet, not a database dump. */
    private const MAX_ROWS = 50000;

    /** PDF is laid out in memory before a byte is written, so it gets a far lower ceiling. */
    private const MAX_PDF_ROWS = 2000;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * What this list can export, for the column picker.
     *
     * Served rather than repeated in each page: a column list that lives in two places is a
     * column list that disagrees with itself by the second change.
     */
    public function columns(Request $request, string $resource): JsonResponse
    {
        $definition = ExportRegistry::find($resource) ?? abort(404);

        abort_unless($request->user()?->hasPermission($definition['permission']), 403);

        return response()->json([
            'label' => $definition['label'],
            'columns' => array_keys($definition['columns']),
        ]);
    }

    public function __invoke(Request $request, string $resource): Response|StreamedResponse
    {
        $definition = ExportRegistry::find($resource) ?? abort(404, 'Nothing exportable by that name.');

        abort_unless($request->user()?->hasPermission($definition['permission']), 403);

        $columns = $this->chosenColumns($request, $definition);

        abort_if($columns === [], 422, 'Choose at least one column.');

        $format = strtolower((string) $request->query('format', 'csv'));

        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 422, 'Unknown format.');

        $query = $this->query($request, $definition, $columns, $format === 'pdf' ? self::MAX_PDF_ROWS : self::MAX_ROWS);

        $this->audit->recordTable('exports', 0, 'exported', null, [
            'resource' => $resource,
            'format' => $format,
            'columns' => array_keys($columns),
            'filters' => $request->except(['columns', 'page', 'format']),
        ]);

        $filename = $resource.'-'.now()->format('Y-m-d-His').'.'.$format;

        return match ($format) {
            'xlsx' => $this->xlsx($query, $columns, $filename),
            'pdf' => $this->pdf($query, $columns, $definition, $request, $filename),
            default => $this->csv($query, $columns, $filename),
        };
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, string>  $columns
     */
    private function csv($query, array $columns, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query, $columns): void {
            $handle = fopen('php://output', 'wb');

            // Excel opens a UTF-8 CSV as Latin-1 without this, which mangles Bangla names.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_keys($columns));

            foreach ($this->rows($query, $columns) as $values) {
                fputcsv($handle, $values);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * A real spreadsheet, for the people who were opening the CSV in Excel anyway.
     *
     * Written straight to the output stream, chunk by chunk, for the same reason the CSV is:
     * a workbook assembled in memory is a workbook that dies at fifty thousand rows.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, string>  $columns
     */
    private function xlsx($query, array $columns, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query, $columns): void {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');

            $header = (new Style)->withFontBold(true);

            $writer->addRow(Row::fromValuesWithStyle(array_keys($columns), $header));

            foreach ($this->rows($query, $columns) as $values) {
                $writer->addRow(Row::fromValues($values));
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * A document to print or sign, not a file to reopen.
     *
     * Assembled in memory rather than streamed, which is why it carries a far lower row
     * ceiling than the other two: dompdf lays out the whole table before it writes a byte,
     * and a fifty-thousand-row table is how a request runs out of memory.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, string>  $columns
     * @param  array<string, mixed>  $definition
     */
    private function pdf($query, array $columns, array $definition, Request $request, string $filename): Response
    {
        $rows = [];

        foreach ($this->rows($query, $columns) as $values) {
            $rows[] = $values;
        }

        $pdf = Pdf::loadView('exports.table', [
            'title' => $definition['label'],
            'headings' => array_keys($columns),
            'rows' => $rows,
            'truncated' => count($rows) >= self::MAX_PDF_ROWS,
            'limit' => self::MAX_PDF_ROWS,
            'filters' => array_filter($request->except(['columns', 'page', 'format'])),
            'printedAt' => now()->format('d M Y H:i'),
        ]);

        // Landscape as the default: an export is wide by nature, and a portrait page turns
        // eight columns into eight columns of one word each.
        $pdf->setPaper('a4', count($columns) > 4 ? 'landscape' : 'portrait');

        return $pdf->download($filename);
    }

    /**
     * Every row of the export, as strings in the order the columns were picked.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, string>  $columns
     * @return \Generator<int, list<string>>
     */
    private function rows($query, array $columns): \Generator
    {
        $aliases = array_keys($this->aliases($columns));

        foreach ($query->cursor() as $row) {
            $values = (array) $row;

            yield array_map(
                fn (string $alias): string => (string) ($values[$alias] ?? ''),
                $aliases,
            );
        }
    }

    /**
     * The columns to export: whatever was ticked, defaulting to all of them, and always in the
     * definition's order rather than the order the checkboxes happened to arrive in.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    private function chosenColumns(Request $request, array $definition): array
    {
        $requested = $request->query('columns');

        if (! is_string($requested) || trim($requested) === '') {
            return $definition['columns'];
        }

        $wanted = array_filter(explode(',', $requested));

        return array_filter(
            $definition['columns'],
            fn (string $label): bool => in_array($label, $wanted, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The alias the base table was given, or its name when it has none.
     *
     * @param  array<string, mixed>  $definition
     */
    private function baseAlias(array $definition): string
    {
        $parts = preg_split('/\s+as\s+/i', $definition['from']);

        return $parts[1] ?? $parts[0];
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, string> alias => expression
     */
    private function aliases(array $columns): array
    {
        $aliases = [];

        foreach ($columns as $label => $expression) {
            $aliases['col_'.md5($label)] = $expression;
        }

        return $aliases;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, string>  $columns
     * @return \Illuminate\Database\Query\Builder
     */
    private function query(Request $request, array $definition, array $columns, int $limit)
    {
        $query = DB::table($definition['from']);

        foreach ($definition['joins'] ?? [] as [$table, $left, $right]) {
            $query->leftJoin($table, $left, '=', $right);
        }

        $term = trim((string) $request->string('q'));

        if ($term !== '' && $definition['searchable'] !== []) {
            $query->where(function ($sub) use ($definition, $term): void {
                foreach ($definition['searchable'] as $column) {
                    $sub->orWhere($column, 'like', "%{$term}%");
                }
            });
        }

        foreach ($definition['filters'] as $key => $column) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        // Sorting matters: the export should come out in the order the screen was showing.
        $sort = (string) $request->query('sort', '');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $sortable = array_map(
            fn (string $expression): string => (string) last(explode('.', $expression)),
            array_values($definition['columns']),
        );

        if ($column !== '' && in_array($column, $sortable, true)) {
            $query->orderBy($column, $direction);
        }

        // `chunk()` refuses to run unordered, and rightly so: without a stable order the same
        // row can appear in two chunks and another can be skipped entirely.
        $query->orderBy($this->baseAlias($definition).'.id');

        foreach ($this->aliases($columns) as $alias => $expression) {
            $query->addSelect(DB::raw("{$expression} as {$alias}"));
        }

        return $query->limit($limit);
    }
}
