<?php

declare(strict_types=1);

namespace App\Support\Print;

use Illuminate\Support\Facades\DB;

/**
 * Every document this system hands to somebody outside it, stated once.
 *
 * A quotation, an order confirmation, a challan and a money receipt are all the same shape of
 * problem — fetch a header, fetch its lines, check the caller may see it, refuse if it is still
 * a draft, log that it left the building — and writing that shape out fourteen times is how the
 * fourteenth one ends up missing the audit row or the draft guard. So the differences live here
 * as data and the machinery lives in DocumentComposer.
 *
 * `withheld` is a blacklist rather than a whitelist on purpose: a status added to the enum
 * later stays printable instead of silently returning 403 on a document somebody needs. What is
 * blacklisted is the state in which the paper would be a lie — an unapproved purchase order
 * (06-rbac §5), an unissued invoice, a challan nobody has released to a driver.
 *
 * `permission` is the resource's `view` right, not a right of its own: if you may read the
 * document on screen you may put it on paper. Whether it may leave the building at all is what
 * the status gate answers.
 */
class DocumentRegistry
{
    /**
     * @return array<string, array{
     *     label: string,
     *     segment: string,
     *     table: string,
     *     permission: string,
     *     view: string,
     *     orientation: 'portrait'|'landscape',
     *     withheld: list<string>,
     *     withheld_reason: string,
     *     load: callable(int): array<string, mixed>,
     * }>
     */
    public static function all(): array
    {
        return [
            'inquiries' => [
                'label' => 'Inquiry acknowledgement',
                'segment' => 'inquiries',
                'table' => 'inquiries',
                'permission' => 'inquiry.view',
                'view' => 'print.inquiry',
                'orientation' => 'portrait',
                'withheld' => ['draft'],
                'withheld_reason' => 'This inquiry is still a draft, so there is nothing to acknowledge yet.',
                'load' => self::inquiry(...),
            ],

            'quotations' => [
                'label' => 'Quotation',
                'segment' => 'quotations',
                'table' => 'quotations',
                'permission' => 'quotation.view',
                'view' => 'print.quotation',
                'orientation' => 'portrait',
                // A draft quotation is printable: a merchandiser reads it on paper before
                // sending it, and that is the whole point of a proof copy.
                'withheld' => [],
                'withheld_reason' => '',
                'load' => self::quotation(...),
            ],

            'sales-orders' => [
                'label' => 'Order confirmation',
                'segment' => 'sales-orders',
                'table' => 'sales_orders',
                'permission' => 'sales_order.view',
                'view' => 'print.sales-order',
                'orientation' => 'portrait',
                // Confirming is the act that makes this a promise. Before it, the customer
                // would be holding an acceptance nobody in this building has given.
                'withheld' => ['draft', 'credit_hold'],
                'withheld_reason' => 'This order has not been confirmed yet, so no confirmation can be issued.',
                'load' => self::salesOrder(...),
            ],

            'invoices' => [
                'label' => 'Invoice',
                'segment' => 'invoices',
                'table' => 'sales_invoices',
                'permission' => 'sales_invoice.view',
                'view' => 'print.sales-invoice',
                'orientation' => 'portrait',
                'withheld' => ['draft'],
                'withheld_reason' => 'This invoice has not been issued yet, so it cannot be printed.',
                'load' => self::salesInvoice(...),
            ],

            'credit-notes' => [
                'label' => 'Credit note',
                'segment' => 'credit-notes',
                'table' => 'credit_notes',
                'permission' => 'credit_note.view',
                'view' => 'print.credit-note',
                'orientation' => 'portrait',
                'withheld' => ['draft'],
                'withheld_reason' => 'This credit note has not been approved yet, so it cannot be printed.',
                'load' => self::creditNote(...),
            ],

            'receipts' => [
                'label' => 'Money receipt',
                'segment' => 'receipts',
                'table' => 'receipts',
                'permission' => 'receipt.view',
                'view' => 'print.receipt',
                'orientation' => 'portrait',
                // A receipt for money not yet booked is a receipt for money not received.
                'withheld' => ['draft'],
                'withheld_reason' => 'This receipt has not been posted yet, so it cannot be issued.',
                'load' => self::receipt(...),
            ],

            'packing-lists' => [
                'label' => 'Packing list',
                'segment' => 'packing-lists',
                'table' => 'packing_lists',
                'permission' => 'packing_list.view',
                'view' => 'print.packing-list',
                'orientation' => 'portrait',
                'withheld' => ['draft'],
                'withheld_reason' => 'This packing list is still being built, so the carton breakdown is not final.',
                'load' => self::packingList(...),
            ],

            'delivery-challans' => [
                'label' => 'Delivery challan',
                'segment' => 'delivery-challans',
                'table' => 'delivery_challans',
                'permission' => 'delivery_challan.view',
                'view' => 'print.delivery-challan',
                'orientation' => 'portrait',
                // The challan travels with the goods. Issuing it is what releases them.
                'withheld' => ['draft'],
                'withheld_reason' => 'This challan has not been issued yet, so it cannot travel with a consignment.',
                'load' => self::deliveryChallan(...),
            ],

            'purchase-orders' => [
                'label' => 'Purchase order',
                'segment' => 'purchase-orders',
                'table' => 'purchase_orders',
                'permission' => 'purchase_order.view',
                'view' => 'print.purchase-order',
                'orientation' => 'portrait',
                // An unapproved order is not a document anyone should be holding: printing one
                // is how a supplier ends up shipping against a price nobody signed off.
                'withheld' => ['draft', 'pending_approval'],
                'withheld_reason' => 'This order has not been approved yet, so it cannot be printed.',
                'load' => self::purchaseOrder(...),
            ],

            'rfqs' => [
                'label' => 'Request for quotation',
                'segment' => 'rfqs',
                'table' => 'supplier_rfqs',
                'permission' => 'rfq.view',
                'view' => 'print.supplier-rfq',
                'orientation' => 'portrait',
                'withheld' => ['draft'],
                'withheld_reason' => 'This request has not been issued yet, so it cannot be sent to a supplier.',
                'load' => self::supplierRfq(...),
            ],

            'test-reports' => [
                'label' => 'Test report',
                'segment' => 'lab/reports',
                'table' => 'test_reports',
                'permission' => 'test_report.view',
                'view' => 'print.test-report',
                'orientation' => 'portrait',
                // QC3: immutable once issued, and a reprint reproduces the issued values. A
                // draft report in a brand's hands is a result that can still change.
                'withheld' => ['draft'],
                'withheld_reason' => 'This report has not been issued yet, so it cannot be released.',
                'load' => self::testReport(...),
            ],

            'job-cards' => [
                'label' => 'Job card',
                'segment' => 'job-cards',
                'table' => 'job_cards',
                'permission' => 'job_card.view',
                'view' => 'print.job-card',
                'orientation' => 'portrait',
                // Deliberately open at every status: the floor prints a planned card to read
                // the routing before it is released.
                'withheld' => [],
                'withheld_reason' => '',
                'load' => self::jobCard(...),
            ],
        ];
    }

    /**
     * What the front end needs to render a Print and a PDF button.
     *
     * Shared as an Inertia prop rather than restated in JavaScript: a button that offers to print
     * a draft invoice the controller then refuses with a 403 is worse than no button, and two
     * copies of the same status list is how that happens. Twelve small entries, no queries.
     *
     * @return array<string, array{segment: string, label: string, withheld: list<string>}>
     */
    public static function forFrontend(): array
    {
        $documents = [];

        foreach (self::all() as $key => $definition) {
            $documents[$key] = [
                'segment' => $definition['segment'],
                'label' => $definition['label'],
                'withheld' => $definition['withheld'],
            ];
        }

        return $documents;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, mixed> */
    private static function inquiry(int $id): array
    {
        $document = DB::table('inquiries as i')
            ->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')
            ->leftJoin('customer_contacts as cc', 'cc.id', '=', 'i.customer_contact_id')
            ->leftJoin('brands as b', 'b.id', '=', 'i.brand_id')
            ->leftJoin('employees as e', 'e.id', '=', 'i.merchandiser_id')
            ->leftJoin('inquiry_sources as src', 'src.code', '=', 'i.source')
            ->where('i.id', $id)
            ->select([
                'i.*',
                'c.name as customer_name', 'c.email as customer_email', 'c.phone as customer_phone',
                'cc.name as contact_name', 'cc.designation as contact_designation',
                'b.name as brand_name', 'e.name as merchandiser_name', 'src.name as source_name',
            ])
            ->first() ?? abort(404);

        $lines = DB::table('inquiry_lines as l')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('product_types as pt', 'pt.code', '=', 'l.product_type')
            ->where('l.inquiry_id', $id)
            ->orderBy('l.line_no')
            ->get([
                'l.line_no', 'l.description', 'l.qty', 'l.target_rate_per_m', 'l.notes',
                'p.code as product_code', 'pt.name as product_type_name',
            ]);

        return ['document' => $document, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private static function quotation(int $id): array
    {
        $document = DB::table('quotations as q')
            ->leftJoin('customers as c', 'c.id', '=', 'q.customer_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'q.currency_id')
            ->leftJoin('payment_terms as pt', 'pt.id', '=', 'q.payment_term_id')
            ->where('q.id', $id)
            ->select([
                'q.*', 'c.name as customer_name', 'c.email as customer_email', 'c.phone as customer_phone',
                'cur.code as currency', 'pt.name as payment_terms',
            ])
            ->first() ?? abort(404);

        $lines = DB::table('quotation_lines as l')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.quotation_id', $id)
            ->orderBy('l.line_no')
            ->get([
                'l.line_no', 'l.description', 'l.qty', 'l.rate_per_m', 'l.tooling_charge',
                'l.line_total', 'l.lead_time_days', 'p.code as product_code',
            ]);

        return ['document' => $document, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private static function salesOrder(int $id): array
    {
        $document = DB::table('sales_orders as so')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'so.currency_id')
            ->leftJoin('payment_terms as pt', 'pt.id', '=', 'so.payment_term_id')
            ->leftJoin('order_priorities as pr', 'pr.code', '=', 'so.priority')
            ->leftJoin('quotations as q', 'q.id', '=', 'so.quotation_id')
            ->leftJoin('employees as e', 'e.id', '=', 'so.merchandiser_id')
            ->leftJoin('factory_units as fu', 'fu.id', '=', 'so.factory_unit_id')
            ->where('so.id', $id)
            ->select([
                'so.*', 'c.name as customer_name', 'c.email as customer_email', 'c.phone as customer_phone',
                'cur.code as currency', 'pt.name as payment_terms', 'pr.name as priority_name',
                'q.number as quotation_number', 'e.name as merchandiser_name',
                'fu.name as unit_name', 'fu.address as unit_address',
            ])
            ->first() ?? abort(404);

        $lines = DB::table('sales_order_lines as l')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('artwork_versions as av', 'av.id', '=', 'l.artwork_version_id')
            ->leftJoin('artworks as a', 'a.id', '=', 'av.artwork_id')
            ->where('l.sales_order_id', $id)
            ->orderBy('l.line_no')
            ->get([
                'l.id', 'l.line_no', 'l.description', 'l.ordered_qty', 'l.rate_per_m',
                'l.tooling_charge', 'l.line_total', 'l.promised_date', 'l.status',
                'l.over_tolerance_pct', 'l.under_tolerance_pct',
                'p.code as product_code', 'p.name as product_name', 'p.customer_style_ref',
                'a.code as artwork_code', 'av.version_no as artwork_version',
            ]);

        // The schedule is what the customer actually reads: one confirmed quantity split across
        // three dates is three commitments, not one.
        $schedules = DB::table('so_delivery_schedules as s')
            ->join('sales_order_lines as l', 'l.id', '=', 's.sales_order_line_id')
            ->where('l.sales_order_id', $id)
            ->orderBy('l.line_no')
            ->orderBy('s.sequence_no')
            ->get(['l.line_no', 's.sequence_no', 's.qty', 's.due_date'])
            ->groupBy('line_no');

        return ['document' => $document, 'lines' => $lines, 'schedules' => $schedules];
    }

    /** @return array<string, mixed> */
    private static function salesInvoice(int $id): array
    {
        $document = DB::table('sales_invoices as si')
            ->leftJoin('customers as c', 'c.id', '=', 'si.customer_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'si.currency_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'si.sales_order_id')
            ->leftJoin('payment_terms as pt', 'pt.id', '=', 'so.payment_term_id')
            ->leftJoin('delivery_challans as dc', 'dc.id', '=', 'si.delivery_challan_id')
            ->where('si.id', $id)
            ->select([
                'si.*', 'c.name as customer_name', 'c.email as customer_email', 'c.phone as customer_phone',
                'c.bin_no as customer_bin', 'c.tin_no as customer_tin',
                'cur.code as currency', 'pt.name as payment_terms',
                'so.number as order_number', 'so.customer_po_no', 'dc.number as challan_number',
            ])
            ->first() ?? abort(404);

        $lines = DB::table('sales_invoice_lines as l')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('taxes as t', 't.id', '=', 'l.tax_id')
            ->where('l.sales_invoice_id', $id)
            ->orderBy('l.line_no')
            ->get([
                'l.line_no', 'l.description', 'l.qty', 'l.rate_per_m', 'l.tax_amount', 'l.amount',
                'p.code as product_code', 't.name as tax_name',
            ]);

        return ['document' => $document, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private static function creditNote(int $id): array
    {
        $document = DB::table('credit_notes as cn')
            ->leftJoin('customers as c', 'c.id', '=', 'cn.customer_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'cn.currency_id')
            ->leftJoin('sales_invoices as si', 'si.id', '=', 'cn.sales_invoice_id')
            ->leftJoin('ncrs as n', 'n.id', '=', 'cn.ncr_id')
            ->leftJoin('users as u', 'u.id', '=', 'cn.approved_by')
            ->where('cn.id', $id)
            ->select([
                'cn.*', 'c.name as customer_name', 'c.email as customer_email',
                'c.bin_no as customer_bin', 'cur.code as currency',
                'si.number as invoice_number', 'si.invoice_date', 'si.total as invoice_total',
                'n.number as ncr_number', 'u.name as approved_by_name',
            ])
            ->first() ?? abort(404);

        return ['document' => $document, 'lines' => collect()];
    }

    /** @return array<string, mixed> */
    private static function receipt(int $id): array
    {
        $document = DB::table('receipts as r')
            ->leftJoin('customers as c', 'c.id', '=', 'r.customer_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'r.currency_id')
            ->where('r.id', $id)
            ->select([
                'r.*', 'c.name as customer_name', 'c.email as customer_email',
                'c.phone as customer_phone', 'cur.code as currency',
            ])
            ->first() ?? abort(404);

        // What the money was set against. An unallocated receipt is legitimate — it is money on
        // account — so this is allowed to come back empty.
        $lines = DB::table('receipt_allocations as ra')
            ->join('sales_invoices as si', 'si.id', '=', 'ra.sales_invoice_id')
            ->where('ra.receipt_id', $id)
            ->orderBy('si.invoice_date')
            ->get(['si.number as invoice_number', 'si.invoice_date', 'si.total as invoice_total', 'ra.amount']);

        return ['document' => $document, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private static function packingList(int $id): array
    {
        $document = DB::table('packing_lists as pl')
            ->leftJoin('customers as c', 'c.id', '=', 'pl.customer_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'pl.sales_order_id')
            ->leftJoin('customer_addresses as ca', 'ca.id', '=', 'pl.delivery_address_id')
            ->where('pl.id', $id)
            ->select([
                'pl.*', 'c.name as customer_name', 'so.number as order_number', 'so.customer_po_no',
                'ca.line1 as ship_line1', 'ca.line2 as ship_line2', 'ca.city as ship_city',
                'ca.district as ship_district', 'ca.postcode as ship_postcode', 'ca.country as ship_country',
            ])
            ->first() ?? abort(404);

        // Carton by carton, because that is how the consignment is checked at the far end.
        $cartons = DB::table('cartons as ct')
            ->where('ct.packing_list_id', $id)
            ->orderBy('ct.carton_no')
            ->get([
                'ct.id', 'ct.carton_no', 'ct.barcode', 'ct.gross_weight_kg', 'ct.net_weight_kg',
                'ct.length_cm', 'ct.width_cm', 'ct.height_cm',
            ]);

        $contents = DB::table('carton_contents as cc')
            ->join('cartons as ct', 'ct.id', '=', 'cc.carton_id')
            ->leftJoin('products as p', 'p.id', '=', 'cc.product_id')
            ->leftJoin('stock_lots as sl', 'sl.id', '=', 'cc.lot_id')
            ->where('ct.packing_list_id', $id)
            ->orderBy('ct.carton_no')
            ->get([
                'cc.carton_id', 'cc.colourway', 'cc.qty', 'cc.bundles',
                'p.code as product_code', 'p.name as product_name', 'sl.lot_no',
            ])
            ->groupBy('carton_id');

        return ['document' => $document, 'cartons' => $cartons, 'contents' => $contents, 'lines' => collect()];
    }

    /** @return array<string, mixed> */
    private static function deliveryChallan(int $id): array
    {
        $document = DB::table('delivery_challans as dc')
            ->leftJoin('customers as c', 'c.id', '=', 'dc.customer_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'dc.sales_order_id')
            ->leftJoin('packing_lists as pl', 'pl.id', '=', 'dc.packing_list_id')
            ->leftJoin('customer_addresses as ca', 'ca.id', '=', 'dc.delivery_address_id')
            ->leftJoin('trips as tp', 'tp.id', '=', 'dc.trip_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 'tp.vehicle_id')
            ->leftJoin('drivers as dr', 'dr.id', '=', 'tp.driver_id')
            ->where('dc.id', $id)
            ->select([
                'dc.*', 'c.name as customer_name', 'c.phone as customer_phone',
                'so.number as order_number', 'so.customer_po_no', 'pl.number as packing_list_number',
                'ca.line1 as ship_line1', 'ca.line2 as ship_line2', 'ca.city as ship_city',
                'ca.district as ship_district', 'ca.postcode as ship_postcode', 'ca.country as ship_country',
                'v.registration_no as vehicle_no', 'dr.name as driver_name', 'dr.phone as driver_phone',
            ])
            ->first() ?? abort(404);

        $lines = DB::table('delivery_challan_lines as l')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('stock_lots as sl', 'sl.id', '=', 'l.lot_id')
            ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'l.sales_order_line_id')
            ->where('l.delivery_challan_id', $id)
            ->orderBy('l.line_no')
            ->get([
                'l.line_no', 'l.qty', 'l.cartons', 'p.code as product_code', 'p.name as product_name',
                'p.customer_style_ref', 'sl.lot_no', 'sol.description as order_description',
            ]);

        return ['document' => $document, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private static function purchaseOrder(int $id): array
    {
        $document = DB::table('purchase_orders as po')
            ->leftJoin('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'po.currency_id')
            ->leftJoin('payment_terms as pt', 'pt.id', '=', 'po.payment_term_id')
            ->leftJoin('factory_units as fu', 'fu.id', '=', 'po.factory_unit_id')
            ->where('po.id', $id)
            ->select([
                'po.*', 's.name as supplier_name', 's.address as supplier_address', 's.country as supplier_country',
                's.email as supplier_email', 's.phone as supplier_phone',
                'cur.code as currency', 'pt.name as payment_terms', 'fu.name as unit_name', 'fu.address as unit_address',
            ])
            ->first() ?? abort(404);

        $lines = DB::table('purchase_order_lines as l')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'l.uom_id')
            ->where('l.po_id', $id)
            ->orderBy('l.line_no')
            ->get([
                'l.line_no', 'i.code as item_code', 'i.name as item_name', 'l.qty', 'u.code as uom',
                'l.rate', 'l.amount', 'l.expected_date', 'l.cert_claim',
            ]);

        return ['document' => $document, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private static function supplierRfq(int $id): array
    {
        $document = DB::table('supplier_rfqs as r')
            ->leftJoin('purchase_requisitions as pr', 'pr.id', '=', 'r.pr_id')
            ->leftJoin('factory_units as fu', 'fu.id', '=', 'pr.factory_unit_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.created_by')
            ->where('r.id', $id)
            ->select([
                'r.*', 'pr.number as requisition_number',
                'fu.name as unit_name', 'fu.address as unit_address', 'u.name as raised_by_name',
            ])
            ->first() ?? abort(404);

        $lines = DB::table('supplier_rfq_lines as l')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'l.uom_id')
            ->where('l.rfq_id', $id)
            ->orderBy('l.line_no')
            ->get([
                'l.line_no', 'l.qty', 'i.code as item_code', 'i.name as item_name',
                'i.description as item_description', 'i.shade_code', 'u.code as uom',
            ]);

        return ['document' => $document, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private static function testReport(int $id): array
    {
        $document = DB::table('test_reports as tr')
            ->leftJoin('customers as c', 'c.id', '=', 'tr.customer_id')
            ->leftJoin('products as p', 'p.id', '=', 'tr.product_id')
            ->leftJoin('stock_lots as sl', 'sl.id', '=', 'tr.lot_id')
            ->leftJoin('job_cards as jc', 'jc.id', '=', 'tr.job_card_id')
            ->leftJoin('employees as e', 'e.id', '=', 'tr.technician_id')
            ->where('tr.id', $id)
            ->select([
                'tr.*', 'c.name as customer_name', 'p.code as product_code', 'p.name as product_name',
                'p.customer_style_ref', 'sl.lot_no', 'jc.number as job_card_number',
                'e.name as technician_name', 'e.designation as technician_designation',
            ])
            ->first() ?? abort(404);

        $lines = DB::table('test_report_lines as l')
            ->join('lab_tests as lt', 'lt.id', '=', 'l.lab_test_id')
            ->where('l.test_report_id', $id)
            ->orderBy('lt.code')
            ->get([
                'lt.code as test_code', 'lt.name as test_name', 'lt.method', 'lt.unit',
                'l.result_value', 'l.pass_value', 'l.result', 'l.remarks',
            ]);

        return ['document' => $document, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private static function jobCard(int $id): array
    {
        $document = DB::table('job_cards as j')
            ->leftJoin('products as p', 'p.id', '=', 'j.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('artwork_versions as av', 'av.id', '=', 'j.artwork_version_id')
            ->leftJoin('artworks as a', 'a.id', '=', 'av.artwork_id')
            ->leftJoin('factory_units as fu', 'fu.id', '=', 'j.factory_unit_id')
            ->where('j.id', $id)
            ->select([
                'j.*', 'p.code as product_code', 'p.name as product_name', 'p.product_type',
                'c.name as customer_name', 'a.code as artwork_code', 'av.version_no as artwork_version',
                'av.approved_at as artwork_approved_at', 'fu.name as unit_name',
            ])
            ->first() ?? abort(404);

        $operations = DB::table('job_card_operations as o')
            ->leftJoin('machine_groups as mg', 'mg.id', '=', 'o.machine_group_id')
            ->where('o.job_card_id', $id)
            ->orderBy('o.sequence_no')
            ->get(['o.sequence_no', 'o.code', 'o.name', 'mg.name as machine_group', 'o.planned_minutes', 'o.status']);

        return ['document' => $document, 'operations' => $operations, 'lines' => collect()];
    }
}
