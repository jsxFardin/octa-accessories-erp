<?php

declare(strict_types=1);

namespace App\Support\Validation;

use Illuminate\Validation\Validator;

/**
 * Names fields the way the person filling the form would.
 *
 * Laravel builds a message from the input's path, which on a line-item document is the path
 * through the payload: "The lines.0.product_id field is required." That names a row nobody can
 * see (line 1 is index 0) and a column nobody typed (`product_id` is labelled Product). Six
 * blank lines produced twenty-four of those, and the screen read as broken rather than
 * incomplete.
 *
 * Two rules, applied to every message in the application:
 *
 *  1. A nested path becomes the line it belongs to — `lines.0.qty` reads "quantity on line 1".
 *  2. A foreign key reads as the thing it points at — `customer_id` is "customer", because the
 *     field on screen says Customer and the id is an implementation detail.
 *
 * Anything a controller states explicitly through `$request->validate([...], $messages)` or a
 * custom attribute still wins; this only decides what an unnamed field is called.
 */
final class DocumentValidator extends Validator
{
    /**
     * Domain names for the fields whose column name is not what the form calls them. Anything
     * absent falls back to the column name with its underscores and trailing `id` removed,
     * which is right far more often than not.
     *
     * @var array<string, string>
     */
    private const NAMES = [
        'qty' => 'quantity',
        'ordered_qty' => 'ordered quantity',
        'planned_qty' => 'planned quantity',
        'received_qty' => 'received quantity',
        'min_qty' => 'quantity break',
        'rate_per_m' => 'rate per 1000',
        'target_rate_per_m' => 'target rate per 1000',
        'uom_id' => 'unit',
        'base_uom_id' => 'base unit',
        'lot_id' => 'lot',
        'po_id' => 'purchase order',
        'grn_id' => 'goods receipt',
        'bom_id' => 'bill of materials',
        'aql_plan_id' => 'AQL plan',
        'lc_id' => 'letter of credit',
        'gsm' => 'GSM',
        'coverage_pct' => 'ink coverage',
        'margin_pct' => 'margin',
        'wastage_pct' => 'wastage',
        'under_tolerance_pct' => 'under tolerance',
        'over_tolerance_pct' => 'over tolerance',
        'exchange_rate' => 'exchange rate',
        'bin_no' => 'BIN',
        'tin_no' => 'TIN',
    ];

    /**
     * Line collections whose index is worth naming. Anything else keeps its path, because
     * "row 3" is only useful when the user can see rows.
     *
     * @var array<string, string>
     */
    private const COLLECTIONS = [
        'lines' => 'line',
        'operations' => 'operation',
        'items' => 'item',
        'counts' => 'count',
        'allocations' => 'allocation',
        'cartons' => 'carton',
        'rows' => 'row',
    ];

    public function getDisplayableAttribute($attribute)
    {
        if (preg_match('/^(\w+)\.(\d+)\.(.+)$/', $attribute, $match) === 1) {
            $collection = self::COLLECTIONS[$match[1]] ?? null;

            if ($collection !== null) {
                // "line 1 quantity" rather than "lines.0.qty", and ahead of the field name so
                // the default message reads as a sentence: "The line 1 quantity field is
                // required."
                $field = $this->tidy(parent::getDisplayableAttribute($match[3]), $match[3]);

                return $collection.' '.($match[2] + 1).' '.$field;
            }
        }

        return $this->tidy(parent::getDisplayableAttribute($attribute), $attribute);
    }

    /**
     * Drops the `id` a foreign key carries — but only when the name was derived rather than
     * declared, so a deliberate attribute name is never rewritten.
     */
    private function tidy(string $displayable, string $attribute): string
    {
        // Only rewrite a name Laravel derived from the payload. One a controller or a
        // language file states deliberately is already the right words.
        if ($displayable !== str_replace('_', ' ', $attribute)) {
            return $displayable;
        }

        return self::NAMES[$attribute] ?? preg_replace('/ id$/', '', $displayable) ?? $displayable;
    }
}
