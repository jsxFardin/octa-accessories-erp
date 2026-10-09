<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\Supplier;
use App\Support\Reference\ItemVocabulary;
use App\Support\Reference\Vocabulary;

/**
 * What each list accepts on the way in, and under which permission.
 *
 * The mirror image of {@see \App\Support\Export\ExportRegistry}, and deliberately narrower:
 * only master data is importable. A spreadsheet can state a customer or an item — a flat
 * record whose whole meaning is on one row. It cannot state a sales order, because an order
 * carries lines, a number from a sequence and a state machine, and a file that half-creates
 * one leaves a document nobody can finish.
 *
 * A definition is read three ways:
 *
 *  1. the **guidelines** panel and the sample file are generated from `fields`, so what the
 *     screen documents cannot drift from what the importer accepts;
 *  2. `rules` are handed to the normal validator, so a spreadsheet cannot write a row the
 *     form would have rejected;
 *  3. `key` is the natural key — a row whose key already exists updates that record rather
 *     than failing on the unique index, which is what makes re-uploading a corrected file
 *     safe.
 *
 * Field types:
 *   text | number | integer | boolean | date | email — cast from the cell, then validated.
 *   select — one of `options`.
 *   lookup — matched by name or code against `lookup.table`, written to `column`.
 */
class ImportRegistry
{
    /**
     * @return array<string, array{
     *     label: string,
     *     permission: string,
     *     model: class-string<\Illuminate\Database\Eloquent\Model>,
     *     key: string,
     *     fields: array<string, array<string, mixed>>
     * }>
     */
    public static function all(): array
    {
        return [
            'customers' => [
                'label' => 'Customers',
                'permission' => 'customer.import',
                'model' => Customer::class,
                'key' => 'code',
                'fields' => [
                    'code' => [
                        'type' => 'text', 'required' => true, 'example' => 'CUS-001',
                        // The column widths, not the form's: a form that allows thirty
                        // characters into a VARCHAR(20) fails at the database, and a failure
                        // there aborts a row the importer had already reported as accepted.
                        'rules' => ['required', 'string', 'max:20'],
                        'description' => 'Unique customer code. A code that already exists updates that customer.',
                    ],
                    'name' => [
                        'type' => 'text', 'required' => true, 'example' => 'Nordic Apparel Ltd',
                        'rules' => ['required', 'string', 'max:180'],
                        'description' => 'Registered name',
                    ],
                    'kind' => [
                        'type' => 'select', 'example' => 'brand', 'default' => 'manufacturer',
                        'options' => Vocabulary::codes('customer_kind'),
                        'description' => 'Who is on the other side of the order',
                    ],
                    'email' => [
                        'type' => 'email', 'example' => 'orders@nordic.com',
                        'rules' => ['nullable', 'email', 'max:190'],
                        'description' => 'Primary email address',
                    ],
                    'phone' => [
                        'type' => 'text', 'example' => '+8801700000000',
                        'rules' => ['nullable', 'string', 'max:30'],
                        'description' => 'Primary phone',
                    ],
                    'currency' => [
                        'type' => 'lookup', 'column' => 'currency_id', 'example' => 'USD',
                        'lookup' => ['table' => 'currencies', 'columns' => ['code', 'name']],
                        'description' => 'Currency code or name',
                    ],
                    'payment_term' => [
                        'type' => 'lookup', 'column' => 'payment_term_id', 'example' => 'NET30',
                        'lookup' => ['table' => 'payment_terms', 'columns' => ['code', 'name']],
                        'description' => 'Payment term code or name',
                    ],
                    'credit_limit' => [
                        'type' => 'number', 'example' => '250000', 'default' => 0,
                        'rules' => ['numeric', 'min:0'],
                        'description' => 'Credit ceiling the order rules read (BR-46). Numbers only.',
                    ],
                    'min_order_value' => [
                        'type' => 'number', 'example' => '5000', 'default' => 0,
                        'rules' => ['numeric', 'min:0'],
                        'description' => 'Minimum order value (BR-21)',
                    ],
                    'over_tolerance_pct' => [
                        'type' => 'number', 'example' => '5', 'default' => 0,
                        'rules' => ['numeric', 'min:0', 'max:100'],
                        'description' => 'Over-delivery tolerance in percent (BR-44)',
                    ],
                    'under_tolerance_pct' => [
                        'type' => 'number', 'example' => '3', 'default' => 0,
                        'rules' => ['numeric', 'min:0', 'max:100'],
                        'description' => 'Under-delivery tolerance in percent (BR-44)',
                    ],
                    'bin_no' => [
                        'type' => 'text', 'example' => '000123456',
                        'rules' => ['nullable', 'string', 'max:40'],
                        'description' => 'BIN registration number',
                    ],
                    'tin_no' => [
                        'type' => 'text', 'example' => '987654321',
                        'rules' => ['nullable', 'string', 'max:40'],
                        'description' => 'TIN registration number',
                    ],
                    'is_active' => [
                        'type' => 'boolean', 'example' => 'yes', 'default' => true,
                        'description' => 'yes / no. Inactive customers stop being offered.',
                    ],
                ],
            ],

            'suppliers' => [
                'label' => 'Suppliers',
                'permission' => 'supplier.import',
                'model' => Supplier::class,
                'key' => 'code',
                'fields' => [
                    'code' => [
                        'type' => 'text', 'required' => true, 'example' => 'SUP-001',
                        'rules' => ['required', 'string', 'max:20'],
                        'description' => 'Unique supplier code. A code that already exists updates that supplier.',
                    ],
                    'name' => [
                        'type' => 'text', 'required' => true, 'example' => 'Dhaka Yarn Mills',
                        'rules' => ['required', 'string', 'max:150'],
                        'description' => 'Registered name',
                    ],
                    'country' => [
                        'type' => 'text', 'example' => 'Bangladesh',
                        'rules' => ['nullable', 'string', 'max:60'],
                        'description' => 'Country of supply',
                    ],
                    'address' => [
                        'type' => 'text', 'example' => 'Plot 12, Tejgaon, Dhaka',
                        'rules' => ['nullable', 'string', 'max:255'],
                        'description' => 'Postal address',
                    ],
                    'email' => [
                        'type' => 'email', 'example' => 'sales@dym.com',
                        'rules' => ['nullable', 'email', 'max:190'],
                        'description' => 'Primary email address',
                    ],
                    'phone' => [
                        'type' => 'text', 'example' => '+8801700000000',
                        'rules' => ['nullable', 'string', 'max:30'],
                        'description' => 'Primary phone',
                    ],
                    'currency' => [
                        'type' => 'lookup', 'column' => 'currency_id', 'example' => 'BDT',
                        'lookup' => ['table' => 'currencies', 'columns' => ['code', 'name']],
                        'description' => 'Currency code or name',
                    ],
                    'payment_term' => [
                        'type' => 'lookup', 'column' => 'payment_term_id', 'example' => 'NET45',
                        'lookup' => ['table' => 'payment_terms', 'columns' => ['code', 'name']],
                        'description' => 'Payment term code or name',
                    ],
                    'lead_time_days' => [
                        'type' => 'integer', 'example' => '21', 'default' => 0,
                        'rules' => ['integer', 'min:0', 'max:365'],
                        'description' => 'Quoted lead time in days, used by MRP',
                    ],
                    'rating' => [
                        'type' => 'number', 'example' => '4.2',
                        'rules' => ['nullable', 'numeric', 'min:0', 'max:5'],
                        'description' => 'Vendor rating, 0 to 5',
                    ],
                    'is_approved' => [
                        'type' => 'boolean', 'example' => 'no', 'default' => false,
                        'description' => 'yes / no. A PO may not be submitted to an unapproved supplier.',
                    ],
                    'is_active' => [
                        'type' => 'boolean', 'example' => 'yes', 'default' => true,
                        'description' => 'yes / no',
                    ],
                ],
            ],

            'items' => [
                'label' => 'Items',
                'permission' => 'item.import',
                'model' => Item::class,
                'key' => 'code',
                'fields' => [
                    'code' => [
                        'type' => 'text', 'required' => true, 'example' => 'YRN-30-1',
                        'rules' => ['required', 'string', 'max:40'],
                        'description' => 'Unique item code. A code that already exists updates that item.',
                    ],
                    'name' => [
                        'type' => 'text', 'required' => true, 'example' => 'Polyester yarn 30/1',
                        'rules' => ['required', 'string', 'max:180'],
                        'description' => 'Item name',
                    ],
                    'category' => [
                        'type' => 'lookup', 'required' => true, 'column' => 'item_category_id', 'example' => 'Yarn',
                        'lookup' => ['table' => 'item_categories', 'columns' => ['code', 'name']],
                        'rules' => ['required', 'integer'],
                        'description' => 'Item category code or name. Must already exist.',
                    ],
                    'base_uom' => [
                        'type' => 'lookup', 'required' => true, 'column' => 'base_uom_id', 'example' => 'KG',
                        'lookup' => ['table' => 'uoms', 'columns' => ['code', 'name']],
                        'rules' => ['required', 'integer'],
                        'description' => 'Stocking unit code or name. Must already exist.',
                    ],
                    'purchase_uom' => [
                        'type' => 'lookup', 'column' => 'purchase_uom_id', 'example' => 'KG',
                        'lookup' => ['table' => 'uoms', 'columns' => ['code', 'name']],
                        'description' => 'Buying unit, when it differs from the stocking unit',
                    ],
                    'default_supplier' => [
                        'type' => 'lookup', 'column' => 'default_supplier_id', 'example' => 'SUP-001',
                        'lookup' => ['table' => 'suppliers', 'columns' => ['code', 'name']],
                        'description' => 'Supplier code or name',
                    ],
                    'description' => [
                        'type' => 'text', 'example' => 'Ring spun, raw white',
                        'rules' => ['nullable', 'string', 'max:500'],
                        'description' => 'Longer description',
                    ],
                    'min_order_qty' => [
                        'type' => 'number', 'example' => '100', 'default' => 0,
                        'rules' => ['numeric', 'min:0'],
                        'description' => 'Minimum purchase quantity',
                    ],
                    'order_multiple' => [
                        'type' => 'number', 'example' => '25', 'default' => 1,
                        'rules' => ['numeric', 'gt:0'],
                        'description' => 'Purchase quantities round up to this (BR-25). Never zero.',
                    ],
                    'reorder_level' => [
                        'type' => 'number', 'example' => '500', 'default' => 0,
                        'rules' => ['numeric', 'min:0'],
                        'description' => 'Stock level that triggers a requisition',
                    ],
                    'safety_days' => [
                        'type' => 'integer', 'example' => '7', 'default' => 0,
                        'rules' => ['integer', 'min:0', 'max:365'],
                        'description' => 'Safety stock expressed in days of cover',
                    ],
                    'std_rate' => [
                        'type' => 'number', 'example' => '412.50', 'default' => 0,
                        'rules' => ['numeric', 'min:0'],
                        'description' => 'Standard rate used by costing. Numbers only, no currency symbol.',
                    ],
                    'shade_code' => [
                        'type' => 'text', 'example' => 'WHT-01',
                        'rules' => ['nullable', 'string', 'max:40'],
                        'description' => 'Shade reference',
                    ],
                    'gsm' => [
                        'type' => 'number', 'example' => '180',
                        'rules' => ['nullable', 'numeric', 'gt:0'],
                        'description' => 'Grams per square metre',
                    ],
                    'is_lot_tracked' => [
                        'type' => 'boolean', 'example' => 'yes', 'default' => true,
                        'description' => 'yes / no. Lot-tracked items are received into numbered lots.',
                    ],
                    'is_shade_critical' => [
                        'type' => 'boolean', 'example' => 'no', 'default' => false,
                        'description' => 'yes / no. Shade-critical items may not be mixed across lots.',
                    ],
                    'item_type' => [
                        'type' => 'select', 'example' => 'raw_material', 'default' => 'raw_material',
                        'options' => array_keys(ItemVocabulary::ITEM_TYPES),
                        'description' => 'What kind of thing it is: raw_material, consumable, packaging, component, semi_finished, finished_good, tool or service.',
                    ],
                    'make_or_buy' => [
                        'type' => 'select', 'example' => 'buy', 'default' => 'buy',
                        'options' => array_keys(ItemVocabulary::MAKE_OR_BUY),
                        'description' => 'make / buy. A made item needs a production family.',
                    ],
                    'production_family' => [
                        'type' => 'lookup', 'column' => 'production_family_id', 'example' => '06',
                        'lookup' => ['table' => 'production_families', 'columns' => ['code', 'name']],
                        'description' => 'Production family code or name, 01–10. Required for a made item.',
                    ],
                    'garment_type' => [
                        'type' => 'select', 'example' => 'knit',
                        'options' => array_keys(ItemVocabulary::GARMENT_TYPES),
                        'rules' => ['nullable'],
                        'description' => 'knit / woven / both',
                    ],
                    'material_base' => [
                        'type' => 'select', 'example' => 'textile',
                        'options' => array_keys(ItemVocabulary::MATERIAL_BASES),
                        'rules' => ['nullable'],
                        'description' => 'textile / plastic / metal / paper / film',
                    ],
                    'status' => [
                        'type' => 'select', 'example' => 'active', 'default' => 'draft',
                        'options' => Vocabulary::codes('item_status'),
                        'description' => 'Lifecycle stage. Only an active item may be bought, put on a bill or ordered.',
                    ],
                ],
            ],

        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
