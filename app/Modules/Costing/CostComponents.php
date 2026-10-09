<?php

declare(strict_types=1);

namespace App\Modules\Costing;

/**
 * The "other cost" components a sheet may carry beside material and conversion (spec §4):
 * commission, finance, logistics, inspection, certification, development, subcontract. Each
 * is entered on the sheet as a percentage of the price, an amount per unit, or a lump sum
 * for the order, and lands on the sheet as a line of its own with the rule `CS-O`.
 */
final class CostComponents
{
    public const BASES = ['pct_of_price', 'per_unit', 'amount'];

    /** @var list<array{key: string, label: string, cost_type: string, basis: string}> */
    public const DEFAULTS = [
        ['key' => 'commission', 'label' => 'Commission', 'cost_type' => 'commission', 'basis' => 'pct_of_price'],
        ['key' => 'finance', 'label' => 'Finance & LC charges', 'cost_type' => 'finance', 'basis' => 'pct_of_price'],
        ['key' => 'freight', 'label' => 'Freight & logistics', 'cost_type' => 'freight', 'basis' => 'amount'],
        ['key' => 'courier', 'label' => 'Courier', 'cost_type' => 'freight', 'basis' => 'amount'],
        ['key' => 'inspection', 'label' => 'Inspection', 'cost_type' => 'other', 'basis' => 'amount'],
        ['key' => 'certificate', 'label' => 'Certificate', 'cost_type' => 'other', 'basis' => 'amount'],
        ['key' => 'lab_test', 'label' => 'Lab test', 'cost_type' => 'development', 'basis' => 'amount'],
        ['key' => 'development', 'label' => 'Development & samples', 'cost_type' => 'development', 'basis' => 'amount'],
        ['key' => 'subcontract', 'label' => 'Subcontract', 'cost_type' => 'subcontract', 'basis' => 'per_unit'],
    ];

    public const FORMULA = 'CS-O';

    /**
     * Entered components, cleaned: only known bases, only non-zero values, labels from the
     * catalogue where the key is known.
     *
     * @param  iterable<array<string, mixed>>  $entries
     * @return list<array{key: string, label: string, cost_type: string, basis: string, value: float}>
     */
    public static function normalise(iterable $entries): array
    {
        $known = [];

        foreach (self::DEFAULTS as $component) {
            $known[$component['key']] = $component;
        }

        $clean = [];

        foreach ($entries as $entry) {
            $value = (float) ($entry['value'] ?? 0);
            $key = (string) ($entry['key'] ?? '');

            if ($value == 0.0 || $key === '') {
                continue;
            }

            $base = $known[$key] ?? null;
            $basis = (string) ($entry['basis'] ?? $base['basis'] ?? 'amount');

            $clean[] = [
                'key' => $key,
                'label' => (string) ($entry['label'] ?? $base['label'] ?? ucfirst(str_replace('_', ' ', $key))),
                'cost_type' => (string) ($base['cost_type'] ?? $entry['cost_type'] ?? 'other'),
                'basis' => in_array($basis, self::BASES, true) ? $basis : 'amount',
                'value' => $value,
            ];
        }

        return $clean;
    }

    /**
     * The components back out of a stored sheet, for a form that edits them.
     *
     * @param  iterable<object|array<string, mixed>>  $lines
     * @return list<array{key: string, label: string, basis: string, value: float}>
     */
    public static function fromLines(iterable $lines): array
    {
        $byLabel = [];

        foreach (self::DEFAULTS as $component) {
            $byLabel[$component['label']] = $component['key'];
        }

        $out = [];

        foreach ($lines as $line) {
            $row = is_array($line) ? $line : (array) (method_exists($line, 'attributesToArray') ? $line->attributesToArray() : $line);

            if (($row['formula_ref'] ?? null) !== self::FORMULA) {
                continue;
            }

            $basis = match ($row['basis_uom'] ?? '') {
                '%' => 'pct_of_price',
                'unit' => 'per_unit',
                default => 'amount',
            };

            $out[] = [
                'key' => $byLabel[$row['description']] ?? \Illuminate\Support\Str::snake((string) $row['description']),
                'label' => (string) $row['description'],
                'basis' => $basis,
                'value' => $basis === 'pct_of_price' ? (float) $row['qty'] : (float) $row['rate'],
            ];
        }

        return $out;
    }
}
