<?php

declare(strict_types=1);

namespace App\Support\Reference;

use App\Support\Calculators\CutTypeRule;
use App\Support\Calculators\ProductTypeRule;
use Illuminate\Support\Facades\DB;

/**
 * The vocabularies — the dropdowns that used to be PHP enums behind CHECK constraints.
 *
 * They are tables now (docs/02a-schema.sql §1a), edited in Setup like every other lookup, and
 * the behaviour that decided BR-4, BR-9, BR-10, BR-11, BR-13 and BR-33 is columns on those
 * rows rather than `match` arms. This class is the one place that reads them, so a screen
 * stops hardcoding "Garment manufacturer" in one place and "Manufacturer" in another.
 *
 * Rows are memoised per request: a cost sheet prices twenty lines and each one asks for the
 * same product type.
 */
class Vocabulary
{
    /**
     * The table behind each key.
     *
     * @var array<string, string>
     */
    public const TABLES = [
        'product_type' => 'product_types',
        'cut_type' => 'cut_types',
        'customer_kind' => 'customer_kinds',
        'inquiry_source' => 'inquiry_sources',
        'order_priority' => 'order_priorities',
        'product_status' => 'product_statuses',
        'defect_severity' => 'defect_severities',
        'qc_disposition' => 'qc_dispositions',
    ];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $cache = [];

    /**
     * Every row of a vocabulary, active first-class citizens only, in display order.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(string $key, bool $activeOnly = true): array
    {
        $table = self::TABLES[$key] ?? null;

        if ($table === null) {
            return [];
        }

        self::$cache[$table] ??= DB::table($table)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        if (! $activeOnly) {
            return self::$cache[$table];
        }

        return array_values(array_filter(
            self::$cache[$table],
            fn (array $row): bool => (bool) ($row['is_active'] ?? true),
        ));
    }

    /**
     * A single row by code, active or not — a document written last year may carry a value
     * that has since been retired, and it still has to render.
     *
     * @return array<string, mixed>|null
     */
    public static function row(string $key, ?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }

        foreach (self::rows($key, activeOnly: false) as $row) {
            if ((string) $row['code'] === $code) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, string> code => name */
    public static function values(string $key): array
    {
        $values = [];

        foreach (self::rows($key) as $row) {
            $values[(string) $row['code']] = (string) $row['name'];
        }

        return $values;
    }

    /**
     * As a `SelectInput` expects them.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(string $key): array
    {
        return array_map(
            fn (array $row): array => ['value' => (string) $row['code'], 'label' => (string) $row['name']],
            self::rows($key),
        );
    }

    /**
     * For `Rule::in()`. Retired codes are excluded: a form must not offer one, while a stored
     * document keeps rendering it.
     *
     * @return list<string>
     */
    public static function codes(string $key): array
    {
        return array_map(fn (array $row): string => (string) $row['code'], self::rows($key));
    }

    public static function label(string $key, ?string $code): ?string
    {
        $row = self::row($key, $code);

        return $row === null ? $code : (string) $row['name'];
    }

    /** BR-9 · BR-10 · BR-11 · BR-13 — the costing behaviour of one product type. */
    public static function productType(?string $code): ProductTypeRule
    {
        $row = self::row('product_type', $code);

        if ($row === null) {
            return ProductTypeRule::neutral($code ?? 'other');
        }

        return new ProductTypeRule(
            code: (string) $row['code'],
            label: (string) $row['name'],
            consumesYarn: (bool) $row['consumes_yarn'],
            consumesSheets: (bool) $row['consumes_sheets'],
            defaultInkLayGsm: $row['default_ink_lay_gsm'] === null ? null : (float) $row['default_ink_lay_gsm'],
            requiresToolPerColour: (bool) $row['requires_tool_per_colour'],
        );
    }

    /** BR-4 · BR-13 — the cut gap and tooling of one cut type. */
    public static function cutType(?string $code): CutTypeRule
    {
        $row = self::row('cut_type', $code);

        if ($row === null) {
            return CutTypeRule::neutral($code ?? 'straight_cut');
        }

        return new CutTypeRule(
            code: (string) $row['code'],
            label: (string) $row['name'],
            defaultCutGapMm: (float) $row['default_cut_gap_mm'],
            requiresTool: (bool) $row['requires_tool'],
        );
    }

    /** Between requests in a queue worker, and after Setup writes a row. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
