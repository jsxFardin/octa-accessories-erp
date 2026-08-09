<?php

declare(strict_types=1);

namespace App\Support\Reference;

/**
 * The lookup tables behind every dropdown in the application.
 *
 * Twenty tables — departments, warehouses, UoMs, currencies, tax codes, defect codes — had no
 * screen at all: the only way to add a department was to edit a seeder and reseed. They are
 * all the same shape (a short list of rows with a code, a name and a few attributes), so they
 * get one definition each here rather than twenty near-identical controllers.
 *
 * Two rules this file follows, because breaking either produces a screen that saves nothing:
 *
 *  1. Fields mirror `02a-schema.sql` exactly. Generated columns (`item_key`, `base_key`) are
 *     never listed — the database computes them and rejects a write.
 *  2. Every `select` repeats the column's CHECK constraint vocabulary verbatim. A value the
 *     dropdown offers but the constraint refuses is a 500 the user cannot act on.
 *
 * A lookup earns a screen of its own when it has behaviour rather than fields — employees
 * carry a user link and badge login, price lists carry lines. Those live elsewhere.
 *
 * Field types the form understands:
 *   text · textarea · number · decimal · date · time · boolean · select · reference
 */
class ReferenceRegistry
{
    /**
     * Grouped for the Setup hub, in the order the groups are read.
     *
     * @var array<string, string>
     */
    public const GROUPS = [
        'organisation' => 'Organisation',
        'people' => 'People',
        'commercial' => 'Commercial',
        'measurement' => 'Units & money',
        'inventory' => 'Inventory',
        'production' => 'Production',
        'quality' => 'Quality',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            // --- Organisation ---------------------------------------------------------
            'factory-units' => [
                'table' => 'factory_units',
                'group' => 'organisation',
                'label' => 'Factory units',
                'singular' => 'factory unit',
                'icon' => 'building',
                'description' => 'Physical plants. Every document belongs to one, and user scoping follows it (06-rbac §4).',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:150']],
                    ['name' => 'address', 'label' => 'Address', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:255']],
                    ['name' => 'timezone', 'label' => 'Timezone', 'type' => 'text', 'rules' => ['required', 'string', 'max:60'], 'default' => 'Asia/Dhaka'],
                    ['name' => 'is_active', 'label' => 'Active', 'type' => 'boolean', 'default' => true],
                ],
            ],

            'departments' => [
                'table' => 'departments',
                'group' => 'organisation',
                'label' => 'Departments',
                'singular' => 'department',
                'icon' => 'users',
                'description' => 'Weaving, printing, cutting, QC, store. Requisitions and employees are filed against them.',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'factory_unit_id', 'label' => 'Factory unit', 'type' => 'reference', 'reference' => 'factory_units', 'rules' => ['required', 'integer', 'exists:factory_units,id']],
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20']],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:120']],
                    ['name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['design', 'plate', 'screen', 'weaving', 'printing', 'cutting', 'folding', 'qc', 'lab', 'store', 'packing', 'dispatch', 'maintenance', 'admin']],
                ],
                'uniqueWith' => ['factory_unit_id', 'code'],
            ],

            'shifts' => [
                'table' => 'shifts',
                'group' => 'organisation',
                'label' => 'Shifts',
                'singular' => 'shift',
                'icon' => 'planning',
                'description' => 'Working windows. Capacity is computed from shift minutes less breaks and planned downtime (BR-27).',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'factory_unit_id', 'label' => 'Factory unit', 'type' => 'reference', 'reference' => 'factory_units', 'rules' => ['required', 'integer', 'exists:factory_units,id']],
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20']],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:80']],
                    ['name' => 'starts_at', 'label' => 'Starts', 'type' => 'time', 'rules' => ['required']],
                    ['name' => 'ends_at', 'label' => 'Ends', 'type' => 'time', 'rules' => ['required'], 'hint' => 'A night shift may end before it starts; the calendar handles the roll-over.'],
                    ['name' => 'break_minutes', 'label' => 'Break', 'unit' => 'min', 'type' => 'number', 'rules' => ['integer', 'min:0'], 'default' => 0],
                ],
                'uniqueWith' => ['factory_unit_id', 'code'],
            ],

            // --- People ---------------------------------------------------------------
            'employees' => [
                'table' => 'employees',
                'group' => 'people',
                'label' => 'Employees',
                'singular' => 'employee',
                'icon' => 'users',
                'permission' => 'employee',
                'description' => 'Designers, operators and inspectors are picked by name from here, and the card number is what badges in at the floor terminal. A user account is optional — most operators never sign in to a desk.',
                'searchable' => ['code', 'name', 'card_no'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Employee code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:120']],
                    ['name' => 'designation', 'label' => 'Designation', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:80']],
                    ['name' => 'factory_unit_id', 'label' => 'Factory unit', 'type' => 'reference', 'reference' => 'factory_units', 'rules' => ['required', 'integer', 'exists:factory_units,id']],
                    ['name' => 'department_id', 'label' => 'Department', 'type' => 'reference', 'reference' => 'departments', 'rules' => ['nullable', 'integer', 'exists:departments,id']],
                    ['name' => 'user_id', 'label' => 'Login account', 'type' => 'reference', 'reference' => 'users', 'rules' => ['nullable', 'integer', 'exists:users,id'], 'unique' => true, 'hint' => 'Links this person to a desk login. Leave empty for floor-only staff.'],
                    ['name' => 'card_no', 'label' => 'Card number', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:40'], 'unique' => true, 'hint' => 'Scanned at the shop-floor terminal.'],
                    ['name' => 'skill_grade', 'label' => 'Skill grade', 'type' => 'select', 'options' => ['A', 'B', 'C', 'trainee'], 'rules' => ['nullable']],
                    ['name' => 'phone', 'label' => 'Phone', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:40']],
                    ['name' => 'joined_on', 'label' => 'Joined', 'type' => 'date', 'rules' => ['nullable', 'date']],
                    ['name' => 'is_active', 'label' => 'Active', 'type' => 'boolean', 'default' => true],
                ],
            ],

            // --- Commercial -----------------------------------------------------------
            'brands' => [
                'table' => 'brands',
                'group' => 'commercial',
                'label' => 'Brands',
                'singular' => 'brand',
                'icon' => 'award',
                'description' => 'A customer’s labels usually carry a brand of their own; products and artwork are filed under it.',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:150']],
                    ['name' => 'customer_id', 'label' => 'Customer', 'type' => 'reference', 'reference' => 'customers', 'rules' => ['nullable', 'integer', 'exists:customers,id']],
                    ['name' => 'is_active', 'label' => 'Active', 'type' => 'boolean', 'default' => true],
                ],
            ],

            'buying-houses' => [
                'table' => 'buying_houses',
                'group' => 'commercial',
                'label' => 'Buying houses',
                'singular' => 'buying house',
                'icon' => 'customers',
                'description' => 'The intermediary a brand buys through. An order may be placed by one on the brand’s behalf.',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:150']],
                    ['name' => 'country', 'label' => 'Country', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:60']],
                    ['name' => 'is_active', 'label' => 'Active', 'type' => 'boolean', 'default' => true],
                ],
            ],

            'agents' => [
                'table' => 'agents',
                'group' => 'commercial',
                'label' => 'Agents',
                'singular' => 'agent',
                'icon' => 'customers',
                'description' => 'Commission agents. The rate here is what an order accrues against them.',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:150']],
                    ['name' => 'commission_pct', 'label' => 'Commission', 'unit' => '%', 'type' => 'decimal', 'rules' => ['numeric', 'min:0', 'max:100'], 'default' => 0],
                    ['name' => 'is_active', 'label' => 'Active', 'type' => 'boolean', 'default' => true],
                ],
            ],

            'payment-terms' => [
                'table' => 'payment_terms',
                'group' => 'commercial',
                'label' => 'Payment terms',
                'singular' => 'payment term',
                'icon' => 'card',
                'description' => 'Net days drive the invoice due date and the ageing buckets.',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:80']],
                    ['name' => 'net_days', 'label' => 'Net days', 'unit' => 'days', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0']],
                    ['name' => 'is_lc', 'label' => 'Letter of credit', 'type' => 'boolean', 'default' => false],
                    ['name' => 'is_advance', 'label' => 'Advance payment', 'type' => 'boolean', 'default' => false],
                ],
                'defaultSort' => 'net_days',
            ],

            'customer-contacts' => [
                'table' => 'customer_contacts',
                'group' => 'commercial',
                'label' => 'Customer contacts',
                'singular' => 'contact',
                'icon' => 'mail',
                'permission' => 'customer',
                'description' => 'Who a quotation is addressed to and who signs an artwork approval. The primary contact is the default recipient.',
                'searchable' => ['name', 'email'],
                'fields' => [
                    ['name' => 'customer_id', 'label' => 'Customer', 'type' => 'reference', 'reference' => 'customers', 'rules' => ['required', 'integer', 'exists:customers,id']],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:120']],
                    ['name' => 'designation', 'label' => 'Designation', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:80']],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'text', 'rules' => ['nullable', 'email', 'max:180']],
                    ['name' => 'phone', 'label' => 'Phone', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:40']],
                    ['name' => 'is_primary', 'label' => 'Primary', 'type' => 'boolean', 'default' => false],
                ],
                'defaultSort' => 'customer_id',
            ],

            'supplier-contacts' => [
                'table' => 'supplier_contacts',
                'group' => 'commercial',
                'label' => 'Supplier contacts',
                'singular' => 'contact',
                'icon' => 'mail',
                'permission' => 'supplier',
                'description' => 'Who a purchase order is sent to and who is chased when a delivery is late.',
                'searchable' => ['name', 'email'],
                'fields' => [
                    ['name' => 'supplier_id', 'label' => 'Supplier', 'type' => 'reference', 'reference' => 'suppliers', 'rules' => ['required', 'integer', 'exists:suppliers,id']],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:120']],
                    ['name' => 'designation', 'label' => 'Designation', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:80']],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'text', 'rules' => ['nullable', 'email', 'max:180']],
                    ['name' => 'phone', 'label' => 'Phone', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:40']],
                    ['name' => 'is_primary', 'label' => 'Primary', 'type' => 'boolean', 'default' => false],
                ],
                'defaultSort' => 'supplier_id',
            ],

            // --- Units & money --------------------------------------------------------
            'uoms' => [
                'table' => 'uoms',
                'group' => 'measurement',
                'label' => 'Units of measure',
                'singular' => 'unit',
                'icon' => 'tool',
                'permission' => 'uom',
                'description' => 'Every quantity in the system is expressed in one of these (BR-1).',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:60']],
                    ['name' => 'dimension', 'label' => 'Dimension', 'type' => 'select', 'options' => ['length', 'mass', 'area', 'volume', 'count', 'time']],
                ],
            ],

            'uom-conversions' => [
                'table' => 'uom_conversions',
                'group' => 'measurement',
                'label' => 'UoM conversions',
                'singular' => 'conversion',
                'icon' => 'refresh',
                'permission' => 'uom',
                'description' => 'MD-2 — how many "to" units one "from" unit is worth. A wrong factor silently corrupts every consumption and cost figure in the system, so it is worth checking twice.',
                'searchable' => [],
                'fields' => [
                    ['name' => 'from_uom_id', 'label' => 'From', 'type' => 'reference', 'reference' => 'uoms', 'rules' => ['required', 'integer', 'exists:uoms,id']],
                    ['name' => 'to_uom_id', 'label' => 'To', 'type' => 'reference', 'reference' => 'uoms', 'rules' => ['required', 'integer', 'exists:uoms,id', 'different:from_uom_id']],
                    ['name' => 'factor', 'label' => 'Factor', 'type' => 'decimal', 'step' => '0.00000001', 'rules' => ['required', 'numeric', 'gt:0']],
                    ['name' => 'item_id', 'label' => 'Item-specific', 'type' => 'reference', 'reference' => 'items', 'rules' => ['nullable', 'integer', 'exists:items,id'], 'hint' => 'Leave empty for a global conversion. Yarn kg→m differs by count, so those belong to one item.'],
                ],
                'uniqueWith' => ['item_id', 'from_uom_id', 'to_uom_id'],
                'defaultSort' => 'from_uom_id',
            ],

            'currencies' => [
                'table' => 'currencies',
                'group' => 'measurement',
                'label' => 'Currencies',
                'singular' => 'currency',
                'icon' => 'receipt',
                'permission' => 'currency',
                'description' => 'Exactly one is the base currency — the database enforces it — and costs are computed in it (BR-22).',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'size:3'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:60']],
                    ['name' => 'symbol', 'label' => 'Symbol', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:10']],
                    ['name' => 'is_base', 'label' => 'Base currency', 'type' => 'boolean', 'default' => false],
                ],
            ],

            'exchange-rates' => [
                'table' => 'exchange_rates',
                'group' => 'measurement',
                'label' => 'Exchange rates',
                'singular' => 'rate',
                'icon' => 'refresh',
                'permission' => 'currency',
                'description' => 'MD-6 — entered daily. A document uses the rate effective on its own date, so rates are added rather than edited.',
                'searchable' => [],
                'fields' => [
                    ['name' => 'currency_id', 'label' => 'Currency', 'type' => 'reference', 'reference' => 'currencies', 'rules' => ['required', 'integer', 'exists:currencies,id']],
                    ['name' => 'effective_on', 'label' => 'Effective on', 'type' => 'date', 'rules' => ['required', 'date']],
                    ['name' => 'rate_to_base', 'label' => 'Rate to base', 'type' => 'decimal', 'step' => '0.00000001', 'rules' => ['required', 'numeric', 'gt:0']],
                ],
                'uniqueWith' => ['currency_id', 'effective_on'],
                'defaultSort' => '-effective_on',
            ],

            'taxes' => [
                'table' => 'taxes',
                'group' => 'measurement',
                'label' => 'Tax codes',
                'singular' => 'tax code',
                'icon' => 'receipt',
                'permission' => 'tax',
                'description' => 'VAT, AIT, supplementary duty and withholding rates applied to quotation and invoice lines.',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:80']],
                    ['name' => 'rate_pct', 'label' => 'Rate', 'unit' => '%', 'type' => 'decimal', 'rules' => ['required', 'numeric', 'min:0']],
                    ['name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['vat', 'ait', 'sd', 'withholding']],
                    ['name' => 'is_active', 'label' => 'Active', 'type' => 'boolean', 'default' => true],
                ],
            ],

            // --- Inventory ------------------------------------------------------------
            'item-categories' => [
                'table' => 'item_categories',
                'group' => 'inventory',
                'label' => 'Item categories',
                'singular' => 'category',
                'icon' => 'item',
                'permission' => 'item',
                'description' => 'Yarn, ribbon, ink, packaging. The item class decides which technical attributes an item screen asks for.',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:120']],
                    ['name' => 'item_class', 'label' => 'Item class', 'type' => 'select', 'options' => ['yarn', 'ribbon', 'tape', 'ink', 'chemical', 'paper', 'film', 'adhesive', 'tool_stock', 'packing', 'spare', 'other']],
                    ['name' => 'parent_id', 'label' => 'Parent', 'type' => 'reference', 'reference' => 'item_categories', 'rules' => ['nullable', 'integer', 'exists:item_categories,id']],
                ],
            ],

            'warehouses' => [
                'table' => 'warehouses',
                'group' => 'inventory',
                'label' => 'Warehouses',
                'singular' => 'warehouse',
                'icon' => 'warehouse',
                'permission' => 'warehouse',
                'description' => 'Stock is held per warehouse. Non-nettable ones — quarantine, scrap — are excluded from availability.',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:120']],
                    ['name' => 'factory_unit_id', 'label' => 'Factory unit', 'type' => 'reference', 'reference' => 'factory_units', 'rules' => ['required', 'integer', 'exists:factory_units,id']],
                    ['name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['raw_material', 'ink_chemical', 'tool', 'wip', 'finished_goods', 'packing', 'scrap', 'transit']],
                    ['name' => 'is_nettable', 'label' => 'Counts towards availability', 'type' => 'boolean', 'default' => true],
                    ['name' => 'is_active', 'label' => 'Active', 'type' => 'boolean', 'default' => true],
                ],
            ],

            'bins' => [
                'table' => 'bins',
                'group' => 'inventory',
                'label' => 'Bins',
                'singular' => 'bin',
                'icon' => 'stock',
                'permission' => 'warehouse',
                'description' => 'Locations inside a warehouse. Bin-level put-away and picking only work once these exist.',
                'searchable' => ['code', 'description'],
                'fields' => [
                    ['name' => 'warehouse_id', 'label' => 'Warehouse', 'type' => 'reference', 'reference' => 'warehouses', 'rules' => ['required', 'integer', 'exists:warehouses,id']],
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:30']],
                    ['name' => 'description', 'label' => 'Description', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:150']],
                ],
                'uniqueWith' => ['warehouse_id', 'code'],
                'defaultSort' => 'warehouse_id',
            ],

            'supplier-items' => [
                'table' => 'supplier_items',
                'group' => 'inventory',
                'label' => 'Supplier items',
                'singular' => 'supplier item',
                'icon' => 'supplier',
                'permission' => 'supplier',
                'description' => 'BR-26 — lead time and minimum order quantity are per supplier-item, never global. MRP plans against the figure here, so an empty list means every shortage is planned with a zero lead time.',
                'searchable' => ['supplier_code'],
                'fields' => [
                    ['name' => 'supplier_id', 'label' => 'Supplier', 'type' => 'reference', 'reference' => 'suppliers', 'rules' => ['required', 'integer', 'exists:suppliers,id']],
                    ['name' => 'item_id', 'label' => 'Item', 'type' => 'reference', 'reference' => 'items', 'rules' => ['required', 'integer', 'exists:items,id']],
                    ['name' => 'supplier_code', 'label' => 'Their code', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:60'], 'hint' => 'What the supplier calls this item on their own invoice.'],
                    ['name' => 'last_rate', 'label' => 'Last rate', 'type' => 'decimal', 'step' => '0.0001', 'rules' => ['nullable', 'numeric', 'min:0']],
                    ['name' => 'currency_id', 'label' => 'Currency', 'type' => 'reference', 'reference' => 'currencies', 'rules' => ['nullable', 'integer', 'exists:currencies,id']],
                    ['name' => 'lead_time_days', 'label' => 'Lead time', 'unit' => 'days', 'type' => 'number', 'rules' => ['nullable', 'integer', 'min:0']],
                    ['name' => 'moq', 'label' => 'Minimum order', 'type' => 'decimal', 'step' => '0.000001', 'rules' => ['nullable', 'numeric', 'min:0']],
                ],
                'uniqueWith' => ['supplier_id', 'item_id'],
                'defaultSort' => 'supplier_id',
            ],

            // --- Production -----------------------------------------------------------
            'machine-groups' => [
                'table' => 'machine_groups',
                'group' => 'production',
                'label' => 'Machine groups',
                'singular' => 'machine group',
                'icon' => 'machine',
                'permission' => 'machine',
                'description' => 'Routing operations are scheduled against a group, not one machine — capacity is pooled across it.',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:120']],
                    ['name' => 'process_type', 'label' => 'Process', 'type' => 'select', 'options' => ['design', 'warping', 'weaving', 'flexo', 'screen', 'heat_transfer', 'offset', 'thermal', 'slitting', 'cutting', 'folding', 'curing', 'lamination', 'packing']],
                    ['name' => 'output_uom', 'label' => 'Output unit', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'default' => 'metre', 'hint' => 'What this group’s standard rate is measured in — metres for a loom, pieces for a folder.'],
                ],
            ],

            'downtime-reasons' => [
                'table' => 'downtime_reasons',
                'group' => 'production',
                'label' => 'Downtime reasons',
                'singular' => 'reason',
                'icon' => 'machine',
                'permission' => 'downtime',
                'description' => 'What an operator picks when a machine stops. Planned reasons are deducted from available capacity; unplanned ones show up as lost time.',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:120']],
                    ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'options' => ['mechanical', 'electrical', 'material', 'quality', 'changeover', 'power', 'manpower', 'planned', 'other']],
                    ['name' => 'is_planned', 'label' => 'Planned', 'type' => 'boolean', 'default' => false],
                ],
            ],

            // --- Quality --------------------------------------------------------------
            'defects' => [
                'table' => 'defects',
                'group' => 'quality',
                'label' => 'Defect codes',
                'singular' => 'defect code',
                'icon' => 'inspection',
                'permission' => 'qc_inspection',
                'description' => 'The tap-to-count grid on an inspection is built from these. Severity decides the verdict — one critical defect rejects a lot outright.',
                'searchable' => ['code', 'name'],
                'fields' => [
                    ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'rules' => ['required', 'string', 'max:20'], 'unique' => true],
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:120']],
                    ['name' => 'severity', 'label' => 'Severity', 'type' => 'select', 'options' => ['critical', 'major', 'minor']],
                    ['name' => 'process', 'label' => 'Process', 'type' => 'select', 'options' => ['weaving', 'printing', 'cutting', 'folding', 'packing', 'material', 'general'], 'rules' => ['nullable']],
                    ['name' => 'is_active', 'label' => 'Active', 'type' => 'boolean', 'default' => true],
                ],
            ],

            'aql-plans' => [
                'table' => 'aql_plans',
                'group' => 'quality',
                'label' => 'AQL plans',
                'singular' => 'plan',
                'icon' => 'inspection',
                'permission' => 'qc_inspection',
                'description' => 'BR-30 — ISO 2859-1 sampling bands. The sample size and accept number an inspection uses are looked up here, never typed by the inspector.',
                'searchable' => ['standard'],
                'fields' => [
                    ['name' => 'standard', 'label' => 'Standard', 'type' => 'text', 'rules' => ['required', 'string', 'max:30'], 'default' => 'ISO 2859-1'],
                    ['name' => 'inspection_level', 'label' => 'Level', 'type' => 'select', 'options' => ['S-1', 'S-2', 'S-3', 'S-4', 'I', 'II', 'III'], 'default' => 'II'],
                    ['name' => 'aql_value', 'label' => 'AQL', 'type' => 'decimal', 'rules' => ['required', 'numeric', 'gt:0'], 'default' => 2.5],
                    ['name' => 'lot_size_from', 'label' => 'Lot from', 'type' => 'number', 'rules' => ['required', 'integer', 'min:1']],
                    ['name' => 'lot_size_to', 'label' => 'Lot to', 'type' => 'number', 'rules' => ['required', 'integer', 'gte:lot_size_from']],
                    ['name' => 'sample_size', 'label' => 'Sample', 'type' => 'number', 'rules' => ['required', 'integer', 'min:1']],
                    ['name' => 'accept_number', 'label' => 'Accept ≤', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0']],
                    ['name' => 'reject_number', 'label' => 'Reject ≥', 'type' => 'number', 'rules' => ['required', 'integer', 'gt:accept_number']],
                ],
                'uniqueWith' => ['standard', 'inspection_level', 'aql_value', 'lot_size_from'],
                'defaultSort' => 'lot_size_from',
            ],

            'certifications' => [
                'table' => 'certifications',
                'group' => 'quality',
                'label' => 'Certifications',
                'singular' => 'certification',
                'icon' => 'compliance',
                'permission' => 'certification',
                'description' => 'The factory’s own certificates. A claim may only be made against a scheme registered here, and only while its certificate is valid (Gate 2).',
                'searchable' => ['certificate_no', 'issuing_body'],
                'fields' => [
                    ['name' => 'scheme', 'label' => 'Scheme', 'type' => 'select', 'options' => ['FSC', 'GRS', 'OEKO_TEX', 'BSCI', 'SMETA', 'ISO_9001', 'ISO_14001', 'SCOPE', 'OTHER']],
                    ['name' => 'certificate_no', 'label' => 'Certificate number', 'type' => 'text', 'rules' => ['required', 'string', 'max:80']],
                    ['name' => 'issuing_body', 'label' => 'Issued by', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:150'], 'hint' => 'Control Union, Textile Exchange — whoever signed it.'],
                    ['name' => 'issued_on', 'label' => 'Issued on', 'type' => 'date', 'rules' => ['required', 'date']],
                    ['name' => 'expires_on', 'label' => 'Expires on', 'type' => 'date', 'rules' => ['required', 'date', 'after:issued_on']],
                    ['name' => 'scope_description', 'label' => 'Scope', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:500']],
                    ['name' => 'reminder_days', 'label' => 'Remind before', 'unit' => 'days', 'type' => 'number', 'rules' => ['integer', 'min:0'], 'default' => 60],
                    ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['active', 'expired', 'suspended', 'withdrawn'], 'default' => 'active'],
                ],
                'uniqueWith' => ['scheme', 'certificate_no'],
                'defaultSort' => '-expires_on',
            ],

            'certification-scopes' => [
                'table' => 'certification_scopes',
                'group' => 'quality',
                'label' => 'Certification scopes',
                'singular' => 'scope',
                'icon' => 'compliance',
                'permission' => 'certification',
                'description' => 'What each certificate actually covers. The labelled claim and the maximum conversion factor here are what a reconciliation is checked against (BR-42, BR-43).',
                'searchable' => [],
                'fields' => [
                    ['name' => 'certification_id', 'label' => 'Certificate', 'type' => 'reference', 'reference' => 'certifications', 'referenceLabel' => 'certificate_no', 'rules' => ['required', 'integer', 'exists:certifications,id']],
                    ['name' => 'product_type', 'label' => 'Product type', 'type' => 'select', 'options' => ['woven', 'flexo', 'screen', 'heat_transfer', 'offset_tag', 'thermal'], 'rules' => ['nullable']],
                    ['name' => 'item_category_id', 'label' => 'Item category', 'type' => 'reference', 'reference' => 'item_categories', 'rules' => ['nullable', 'integer', 'exists:item_categories,id']],
                    ['name' => 'min_claim_pct', 'label' => 'Minimum claim', 'unit' => '%', 'type' => 'decimal', 'rules' => ['nullable', 'numeric', 'min:0', 'max:100']],
                    ['name' => 'labelled_claim_pct', 'label' => 'Labelled claim', 'unit' => '%', 'type' => 'decimal', 'rules' => ['nullable', 'numeric', 'min:0', 'max:100']],
                    ['name' => 'max_conversion_factor', 'label' => 'Max conversion', 'type' => 'decimal', 'step' => '0.0001', 'rules' => ['nullable', 'numeric', 'gt:0'], 'hint' => 'Output may not exceed certified input times this factor.'],
                ],
                'defaultSort' => 'certification_id',
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /**
     * The permission resource a lookup is governed by.
     *
     * Most share `reference_data`. The ones that already had a permission of their own keep
     * it, so no existing role's rights widen just because a screen appeared.
     */
    public static function permissionResource(string $slug): string
    {
        /** @var string */
        return self::find($slug)['permission'] ?? 'reference_data';
    }
}
