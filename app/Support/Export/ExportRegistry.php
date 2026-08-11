<?php

declare(strict_types=1);

namespace App\Support\Export;

/**
 * What each list exports, and under which permission.
 *
 * One definition per list rather than an export method on twenty controllers — the same
 * reasoning as the reference registry, and the same payoff: the column set is stated once and
 * cannot drift from the screen.
 *
 * Every definition names its own `permission`, so exporting is gated by the same right that
 * lets someone read the list in the first place. `export` is a separate action in the
 * permission catalogue (06-rbac §2) because "may look at the order book" and "may walk out of
 * the building with the order book" are different questions.
 */
class ExportRegistry
{
    /**
     * @return array<string, array{
     *     label: string,
     *     permission: string,
     *     from: string,
     *     joins?: list<array{0: string, 1: string, 2: string}>,
     *     searchable: list<string>,
     *     filters: array<string, string>,
     *     columns: array<string, string>
     * }>
     */
    public static function all(): array
    {
        return [
            'sales-orders' => [
                'label' => 'Sales orders',
                'permission' => 'sales_order.export',
                'from' => 'sales_orders as so',
                'joins' => [['customers as c', 'c.id', 'so.customer_id']],
                'searchable' => ['so.number', 'so.customer_po_no'],
                'filters' => ['status' => 'so.status', 'customer' => 'so.customer_id'],
                'columns' => [
                    'Number' => 'so.number',
                    'Customer' => 'c.name',
                    'Customer PO' => 'so.customer_po_no',
                    'Order date' => 'so.order_date',
                    'Delivery date' => 'so.delivery_date',
                    'Status' => 'so.status',
                    'Total' => 'so.total',
                ],
            ],

            'quotations' => [
                'label' => 'Quotations',
                'permission' => 'quotation.export',
                'from' => 'quotations as q',
                'joins' => [['customers as c', 'c.id', 'q.customer_id']],
                'searchable' => ['q.number'],
                'filters' => ['status' => 'q.status', 'customer' => 'q.customer_id'],
                'columns' => [
                    'Number' => 'q.number',
                    'Revision' => 'q.revision_no',
                    'Customer' => 'c.name',
                    'Date' => 'q.quotation_date',
                    'Valid until' => 'q.valid_until',
                    'Status' => 'q.status',
                    'Total' => 'q.total',
                ],
            ],

            'inquiries' => [
                'label' => 'Inquiries',
                'permission' => 'inquiry.export',
                'from' => 'inquiries as i',
                'joins' => [['customers as c', 'c.id', 'i.customer_id']],
                'searchable' => ['i.number'],
                'filters' => ['status' => 'i.status', 'customer' => 'i.customer_id'],
                'columns' => [
                    'Number' => 'i.number',
                    'Customer' => 'c.name',
                    'Received' => 'i.inquiry_date',
                    'Required by' => 'i.required_by',
                    'Status' => 'i.status',
                ],
            ],

            'customers' => [
                'label' => 'Customers',
                'permission' => 'customer.export',
                'from' => 'customers as c',
                'searchable' => ['c.code', 'c.name', 'c.email'],
                'filters' => ['active' => 'c.is_active'],
                'columns' => [
                    'Code' => 'c.code',
                    'Name' => 'c.name',
                    'Kind' => 'c.kind',
                    'Email' => 'c.email',
                    'Phone' => 'c.phone',
                    'Credit limit' => 'c.credit_limit',
                    'Active' => 'c.is_active',
                ],
            ],

            'suppliers' => [
                'label' => 'Suppliers',
                'permission' => 'supplier.export',
                'from' => 'suppliers as s',
                'searchable' => ['s.code', 's.name'],
                'filters' => ['approved' => 's.is_approved'],
                'columns' => [
                    'Code' => 's.code',
                    'Name' => 's.name',
                    'Country' => 's.country',
                    'Approved' => 's.is_approved',
                    'Rating' => 's.rating',
                ],
            ],

            'items' => [
                'label' => 'Items',
                'permission' => 'item.export',
                'from' => 'items as i',
                'joins' => [['item_categories as cat', 'cat.id', 'i.item_category_id']],
                'searchable' => ['i.code', 'i.name'],
                'filters' => ['category' => 'i.item_category_id'],
                'columns' => [
                    'Code' => 'i.code',
                    'Name' => 'i.name',
                    'Category' => 'cat.name',
                    'Standard rate' => 'i.std_rate',
                    'Average rate' => 'i.avg_rate',
                    'Reorder level' => 'i.reorder_level',
                ],
            ],

            'products' => [
                'label' => 'Products',
                'permission' => 'product.export',
                'from' => 'products as p',
                'joins' => [['customers as c', 'c.id', 'p.customer_id']],
                'searchable' => ['p.code', 'p.name'],
                'filters' => ['customer' => 'p.customer_id', 'type' => 'p.product_type', 'status' => 'p.status'],
                'columns' => [
                    'Code' => 'p.code',
                    'Name' => 'p.name',
                    'Customer' => 'c.name',
                    'Type' => 'p.product_type',
                    'Status' => 'p.status',
                ],
            ],

            'letters-of-credit' => [
                'label' => 'Letters of credit',
                'permission' => 'letter_of_credit.export',
                'from' => 'letters_of_credit as lc',
                'joins' => [['suppliers as s', 's.id', 'lc.supplier_id']],
                'searchable' => ['lc.number', 'lc.lc_no'],
                'filters' => ['status' => 'lc.status', 'supplier' => 'lc.supplier_id', 'kind' => 'lc.kind'],
                'columns' => [
                    'Number' => 'lc.number',
                    'Bank LC no' => 'lc.lc_no',
                    'Supplier' => 's.name',
                    'Kind' => 'lc.kind',
                    'Amount' => 'lc.amount',
                    'Issued' => 'lc.issued_on',
                    'Last shipment' => 'lc.last_shipment_date',
                    'Expiry' => 'lc.expiry_date',
                    'Status' => 'lc.status',
                ],
            ],

            'import-shipments' => [
                'label' => 'Import shipments',
                'permission' => 'import_shipment.export',
                'from' => 'import_shipments as sh',
                'joins' => [['suppliers as s', 's.id', 'sh.supplier_id']],
                'searchable' => ['sh.number', 'sh.invoice_no', 'sh.transport_doc_no', 'sh.bill_of_entry'],
                'filters' => ['status' => 'sh.status', 'supplier' => 'sh.supplier_id', 'mode' => 'sh.mode'],
                'columns' => [
                    'Number' => 'sh.number',
                    'Supplier' => 's.name',
                    'Invoice' => 'sh.invoice_no',
                    'BL / AWB' => 'sh.transport_doc_no',
                    'Mode' => 'sh.mode',
                    'ETA' => 'sh.eta',
                    'Cleared' => 'sh.cleared_on',
                    'Bill of entry' => 'sh.bill_of_entry',
                    'Goods value' => 'sh.goods_value',
                    'Costs' => 'sh.cost_total',
                    'Allocated' => 'sh.allocated_amount',
                    'Status' => 'sh.status',
                ],
            ],

            'expenses' => [
                'label' => 'Expenses',
                'permission' => 'expense.export',
                'from' => 'expenses as e',
                'joins' => [
                    ['expense_categories as ec', 'ec.id', 'e.expense_category_id'],
                    ['factory_units as fu', 'fu.id', 'e.factory_unit_id'],
                ],
                'searchable' => ['e.number', 'e.payee', 'e.reference_no'],
                'filters' => ['status' => 'e.status', 'category' => 'e.expense_category_id', 'method' => 'e.method'],
                'columns' => [
                    'Number' => 'e.number',
                    'Date' => 'e.expense_date',
                    'Category' => 'ec.name',
                    'Factory unit' => 'fu.name',
                    'Payee' => 'e.payee',
                    'Description' => 'e.description',
                    'Amount' => 'e.amount',
                    'Tax' => 'e.tax_amount',
                    'Total' => 'e.total',
                    'Method' => 'e.method',
                    'Reference' => 'e.reference_no',
                    'Status' => 'e.status',
                    'Paid on' => 'e.paid_on',
                ],
            ],

            'purchase-orders' => [
                'label' => 'Purchase orders',
                'permission' => 'purchase_order.export',
                'from' => 'purchase_orders as po',
                'joins' => [['suppliers as s', 's.id', 'po.supplier_id']],
                'searchable' => ['po.number'],
                'filters' => ['status' => 'po.status', 'supplier' => 'po.supplier_id'],
                'columns' => [
                    'Number' => 'po.number',
                    'Supplier' => 's.name',
                    'Ordered' => 'po.order_date',
                    'Expected' => 'po.expected_date',
                    'Status' => 'po.status',
                    'Total' => 'po.total',
                ],
            ],

            'job-cards' => [
                'label' => 'Job cards',
                'permission' => 'job_card.export',
                'from' => 'job_cards as j',
                'searchable' => ['j.number'],
                'filters' => ['status' => 'j.status'],
                'columns' => [
                    'Number' => 'j.number',
                    'Planned qty' => 'j.planned_qty',
                    'Produced qty' => 'j.produced_qty',
                    'Due date' => 'j.due_date',
                    'Priority' => 'j.priority',
                    'Status' => 'j.status',
                ],
            ],

            'lots' => [
                'label' => 'Lots',
                'permission' => 'stock_lot.export',
                'from' => 'stock_lots as l',
                'joins' => [
                    ['items as i', 'i.id', 'l.item_id'],
                    ['warehouses as w', 'w.id', 'l.warehouse_id'],
                ],
                'searchable' => ['l.lot_no'],
                'filters' => ['warehouse' => 'l.warehouse_id', 'status' => 'l.status'],
                'columns' => [
                    'Lot' => 'l.lot_no',
                    'Item' => 'i.code',
                    'Warehouse' => 'w.name',
                    'Shade' => 'l.shade_code',
                    'Balance' => 'l.balance_qty',
                    'Unit cost' => 'l.unit_cost',
                    'Received' => 'l.received_on',
                    'Status' => 'l.status',
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
