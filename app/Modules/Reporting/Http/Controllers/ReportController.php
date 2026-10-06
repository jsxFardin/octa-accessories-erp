<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\ReportCatalogue;
use App\Support\Settings\Organisation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * P2-3 — read-only operational reports. Every figure is the same query the transactional
 * screen already uses; this controller never writes.
 */
class ReportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Reports/Index', [
            'reports' => ReportCatalogue::menu(),
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        $query = ReportCatalogue::make($report);

        $filterKeys = array_merge(
            ['q', 'from', 'to', 'per_page'],
            array_column($query->filterFields(), 'key'),
        );

        return Inertia::render('Reports/Show', [
            'report' => [
                'key' => $query->key(),
                'title' => $query->title(),
                'subtitle' => $query->subtitle(),
                'columns' => $query->columns(),
                'filters' => $query->filters(),
                'document_path' => $query->documentPath(),
            ],
            'rows' => $query->paginate($request),
            'totals' => $query->totals($request),
            // BR-50 — what the totals row is actually in, and what it was made from.
            'totalsMeta' => $query->totalsMeta($request),
            'extras' => $query->extras($request),
            'applied' => $request->only($filterKeys),
        ]);
    }

    /** A printout is one document; past this it is a download. */
    private const PRINT_LIMIT = 2000;

    /**
     * The report as a spreadsheet: every row the filters match, the report's own columns, and
     * the totals the screen shows.
     *
     * Reports had no way out but a screenshot — an ageing list was retyped into Excel to be
     * sent to the people it was about. Values are written raw (ISO dates, plain numbers) so the
     * spreadsheet can sort and sum them; the screen is where they are dressed.
     */
    public function export(Request $request, string $report): StreamedResponse
    {
        $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'csv'])]]);

        $query = ReportCatalogue::make($report);
        $format = $request->query('format', 'xlsx');
        $columns = $query->columns();
        $filename = Str::slug($query->title()).'-'.now()->format('Y-m-d').'.'.$format;

        $rows = function () use ($query, $request, $columns): \Generator {
            yield array_column($columns, 'label');

            foreach ($query->everyRow($request) as $row) {
                yield array_map(fn (array $column): string|float|int => $this->rawValue($column, $row), $columns);
            }

            $totals = $query->totals($request);

            if ($totals !== []) {
                $meta = $query->totalsMeta($request);
                $label = 'Total'.(($meta['converted'] ?? false) ? ' (converted to '.app(Organisation::class)->get('base_currency').')' : '');

                yield array_map(
                    fn (array $column, int $index): string|float => $index === 0 ? $label : ($totals[$column['key']] ?? ''),
                    $columns,
                    array_keys($columns),
                );
            }
        };

        return response()->streamDownload(function () use ($rows, $format): void {
            if ($format === 'csv') {
                $handle = fopen('php://output', 'wb');
                // Excel opens a UTF-8 CSV as Latin-1 without this, which mangles Bangla names.
                fwrite($handle, "\xEF\xBB\xBF");

                foreach ($rows() as $values) {
                    fputcsv($handle, $values);
                }

                fclose($handle);

                return;
            }

            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            $bold = (new Style)->withFontBold(true);

            foreach ($rows() as $index => $values) {
                $writer->addRow($index === 0 ? Row::fromValuesWithStyle($values, $bold) : Row::fromValues($values));
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => $format === 'csv'
                ? 'text/csv; charset=UTF-8'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * The report laid out for paper: all matching rows, what it was filtered by, and its totals.
     *
     * Printing the screen gave one page of twenty-five rows with the sidebar down the side.
     */
    public function print(Request $request, string $report): View
    {
        $query = ReportCatalogue::make($report);
        $organisation = app(Organisation::class);
        $columns = $query->columns();

        $rows = $query->everyRow($request, self::PRINT_LIMIT + 1)->all();
        $truncated = count($rows) > self::PRINT_LIMIT;
        $rows = array_slice($rows, 0, self::PRINT_LIMIT);

        $dateFormat = (string) $organisation->get('date_format');
        // `@numbers=latn`: the number locale sets the grouping (12,34,567 under bn-BD and en-IN),
        // never the script — a printed report must read the same as the screen it came from.
        $numbers = new \NumberFormatter(str_replace('-', '_', (string) $organisation->get('number_locale')).'@numbers=latn', \NumberFormatter::DECIMAL);
        $base = (string) $organisation->get('base_currency');
        $currencyColumn = $query->currencyColumn();

        $shown = function (array $column, mixed $value, ?string $currency) use ($dateFormat, $numbers): string {
            if ($value === null || $value === '') {
                return '—';
            }

            return match ($column['format'] ?? null) {
                'money' => trim(($currency ?? '').' '.$this->number($numbers, (float) $value, 2, 2)),
                'qty' => $this->number($numbers, (float) $value, 0, 3),
                'pct' => $this->number($numbers, (float) $value, 0, 2).'%',
                'date' => \Illuminate\Support\Carbon::parse((string) $value)->format($dateFormat),
                'status' => Str::headline((string) $value),
                default => (string) $value,
            };
        };

        $filterFields = collect($query->filterFields())->keyBy('key');
        $filters = collect($request->only(['q', 'from', 'to', ...$filterFields->keys()->all()]))
            ->filter(fn ($value): bool => $value !== null && $value !== '')
            ->map(function ($value, string $key) use ($filterFields, $dateFormat): array {
                $field = $filterFields->get($key);
                $option = collect($field['options'] ?? [])->first(fn ($option): bool => (string) ($option['value'] ?? '') === (string) $value);

                return [
                    'label' => match ($key) {
                        'q' => 'Search', 'from' => 'From', 'to' => 'To', default => $field['label'] ?? Str::headline($key),
                    },
                    'value' => in_array($key, ['from', 'to'], true)
                        ? \Illuminate\Support\Carbon::parse((string) $value)->format($dateFormat)
                        : (string) ($option['label'] ?? $value),
                ];
            })->values()->all();

        $totals = $query->totals($request);

        return view('reports.print', [
            'title' => $query->title(),
            'organisation' => (string) $organisation->get('org_name'),
            'printedAt' => now($organisation->timezone())->format($dateFormat.' H:i'),
            'columns' => $columns,
            'rows' => array_map(fn (object $row): array => array_map(
                fn (array $column): string => $shown($column, $row->{$column['key']} ?? null, $currencyColumn === null ? null : ($row->{$currencyColumn} ?? null)),
                $columns,
            ), $rows),
            'totals' => array_map(
                fn (array $column): ?string => array_key_exists($column['key'], $totals)
                    ? $shown($column, $totals[$column['key']], ($column['format'] ?? null) === 'money' ? $base : null)
                    : null,
                $columns,
            ),
            'hasTotals' => $totals !== [],
            'converted' => (bool) ($query->totalsMeta($request)['mixed'] ?? false),
            'base' => $base,
            'filters' => $filters,
            'truncated' => $truncated,
            'limit' => self::PRINT_LIMIT,
        ]);
    }

    /**
     * A cell as the spreadsheet should hold it: a number as a number, everything else as text.
     *
     * @param  array<string, mixed>  $column
     */
    private function rawValue(array $column, object $row): string|float|int
    {
        $value = $row->{$column['key']} ?? null;

        if ($value === null || $value === '') {
            return '';
        }

        return match ($column['format'] ?? null) {
            'money', 'qty', 'pct' => (float) $value,
            'date' => substr((string) $value, 0, 10),
            'status' => Str::headline((string) $value),
            default => is_int($value) || is_float($value) ? $value : (string) $value,
        };
    }

    private function number(\NumberFormatter $formatter, float $value, int $min, int $max): string
    {
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $min);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $max);

        return (string) $formatter->format($value);
    }
}
