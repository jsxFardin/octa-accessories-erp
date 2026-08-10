<?php

declare(strict_types=1);

namespace App\Support\Reference;

use App\Support\Calculators\CutType;
use App\Support\Calculators\ProductType;

/**
 * The fixed vocabularies: dropdowns whose values are code, not data.
 *
 * A product type is not a lookup row. `ProductType::consumesYarn()` decides whether BR-9 runs
 * at all, `consumesSheets()` switches BR-11 from metres to sheets, and `requiresToolPerColour()`
 * drives BR-13. Adding "embroidered badge" through a screen would produce a product the
 * consumption calculator has no rule for — and the database would refuse the row anyway,
 * because every one of these columns carries a CHECK constraint.
 *
 * So they stay in code. What was missing was any way to *see* them: an administrator opened
 * Setup, found no list for "Product type", and reasonably concluded something was unfinished.
 * This registry backs a read-only tab that shows each vocabulary, where it is used, and what
 * changing it would actually take.
 *
 * It is also the single source of the labels, so a screen no longer hardcodes
 * "Garment manufacturer" in one place and "Manufacturer" in another.
 */
class Vocabulary
{
    /**
     * @return array<string, array{
     *     label: string,
     *     column: string,
     *     used_for: string,
     *     why_fixed: string,
     *     values: array<string, string>
     * }>
     */
    public static function all(): array
    {
        return [
            'product_type' => [
                'label' => 'Product type',
                'column' => 'products.product_type',
                'used_for' => 'Products, inquiry lines, routings, certification scopes',
                'why_fixed' => 'Each type has its own consumption rule in code — whether it consumes yarn (BR-9), '
                    .'is quoted in sheets rather than metres (BR-11), and how many tools a colour needs (BR-13). '
                    .'A new type would be a product nothing knows how to cost.',
                'values' => self::productTypes(),
            ],

            'cut_type' => [
                'label' => 'Cut type',
                'column' => 'product_specs.cut_type',
                'used_for' => 'Product specifications',
                'why_fixed' => 'The cut gap per type is a setting (Settings → Business rules), but the list itself '
                    .'is fixed: BR-4 adds that gap to the label pitch, and a cut type with no gap has no pitch.',
                'values' => self::cutTypes(),
            ],

            'customer_kind' => [
                'label' => 'Customer kind',
                'column' => 'customers.kind',
                'used_for' => 'Customers',
                'why_fixed' => 'A CHECK constraint on the column. The four kinds describe who is on the other side '
                    .'of the order, which reporting groups by.',
                'values' => [
                    'manufacturer' => 'Garment manufacturer',
                    'brand' => 'Brand',
                    'buying_house' => 'Buying house',
                    'trader' => 'Trader',
                ],
            ],

            'order_priority' => [
                'label' => 'Order priority',
                'column' => 'sales_orders.priority',
                'used_for' => 'Sales orders, planning board ordering',
                'why_fixed' => 'Four levels the planning board sorts by. More levels would not make a queue clearer.',
                'values' => [
                    'low' => 'Low',
                    'normal' => 'Normal',
                    'high' => 'High',
                    'urgent' => 'Urgent',
                ],
            ],

            'product_status' => [
                'label' => 'Product status',
                'column' => 'products.status',
                'used_for' => 'Products',
                'why_fixed' => 'A lifecycle, not a list: only an active product may be ordered, and a discontinued '
                    .'one stays visible on the orders that already used it.',
                'values' => [
                    'development' => 'Development',
                    'active' => 'Active',
                    'on_hold' => 'On hold',
                    'discontinued' => 'Discontinued',
                ],
            ],

            'defect_severity' => [
                'label' => 'Defect severity',
                'column' => 'defects.severity',
                'used_for' => 'Defect codes, QC inspections',
                'why_fixed' => 'The verdict depends on it: one critical defect rejects a lot outright, majors are '
                    .'counted against the AQL accept number, minors are recorded (BR-30, BR-31). '
                    .'The defect codes themselves are editable in Setup → Quality.',
                'values' => [
                    'critical' => 'Critical — rejects the lot on its own',
                    'major' => 'Major — counted against the accept number',
                    'minor' => 'Minor — recorded, does not reject',
                ],
            ],

            'qc_disposition' => [
                'label' => 'QC disposition',
                'column' => 'qc_inspections.disposition',
                'used_for' => 'QC inspections',
                'why_fixed' => 'BR-33 — each disposition has different downstream behaviour: rework returns to an '
                    .'operation, concession needs customer evidence, downgrade re-grades the stock, scrap writes it off.',
                'values' => [
                    'rework' => 'Rework — back to an operation',
                    'concession' => 'Concession — customer accepted',
                    'downgrade' => 'Downgrade — second quality',
                    'scrap' => 'Scrap — written off',
                    'release' => 'Release — accepted as it stands',
                ],
            ],
        ];
    }

    /** @return array<string, string> */
    public static function values(string $key): array
    {
        return self::all()[$key]['values'] ?? [];
    }

    /**
     * As a `SelectInput` expects them, so a screen stops hardcoding its own wording.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(string $key): array
    {
        return array_map(
            fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys(self::values($key)),
            array_values(self::values($key)),
        );
    }

    /** @return array<string, string> */
    private static function productTypes(): array
    {
        $values = [];

        foreach (ProductType::cases() as $case) {
            $values[$case->value] = $case->label();
        }

        return $values;
    }

    /** @return array<string, string> */
    private static function cutTypes(): array
    {
        $values = [];

        foreach (CutType::cases() as $case) {
            $values[$case->value] = ucfirst(str_replace('_', ' ', $case->value));
        }

        return $values;
    }
}
