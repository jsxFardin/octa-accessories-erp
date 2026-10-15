<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DF-6 AC1 — what the commercial invoice, packing list and certificate of origin for an export
 * shipment are drawn from: the shipment's own transport facts, the customer and consignee, and
 * the goods across every delivery note on it. The documents are drafts; a merchandiser checks
 * them against the credit before they go to the bank.
 */
class ExportShipmentPrintData
{
    /** @return array<string, mixed> */
    public static function commercialInvoice(int $id): array
    {
        $document = self::document($id);

        // One line per product and rate across the notes: the invoice totals by what was sold,
        // not by which carton it travelled in.
        $lines = DB::table('delivery_challan_lines as l')
            ->join('delivery_challans as dc', 'dc.id', '=', 'l.delivery_challan_id')
            ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'l.sales_order_line_id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('items as pi', 'pi.id', '=', 'p.item_id')
            ->where('dc.export_shipment_id', $id)
            ->groupBy('l.product_id', 'pi.code', 'pi.name', 'p.customer_style_ref', 'sol.description', 'sol.rate_per_m')
            ->orderBy('pi.code')
            ->selectRaw('pi.code as product_code, pi.name as product_name, p.customer_style_ref, sol.description, sol.rate_per_m,
                SUM(l.qty) as qty, SUM(COALESCE(l.cartons, 0)) as cartons')
            ->get()
            ->map(function (object $line, int $index): object {
                $line->line_no = $index + 1;
                $line->amount = round((float) $line->qty / 1000 * (float) ($line->rate_per_m ?? 0), 4);

                return $line;
            });

        $document->subtotal = round((float) $lines->sum('amount'), 4);
        $document->total = $document->subtotal;

        return ['document' => $document, 'lines' => $lines, 'invoices' => self::invoices($id)];
    }

    /** @return array<string, mixed> */
    public static function packingList(int $id): array
    {
        $document = self::document($id);

        $cartons = DB::table('cartons as ct')
            ->join('packing_lists as pl', 'pl.id', '=', 'ct.packing_list_id')
            ->join('delivery_challans as dc', 'dc.packing_list_id', '=', 'pl.id')
            ->where('dc.export_shipment_id', $id)
            ->orderBy('dc.id')->orderBy('ct.carton_no')
            ->get(['ct.id', 'ct.carton_no', 'ct.barcode', 'ct.gross_weight_kg', 'ct.net_weight_kg', 'ct.length_cm', 'ct.width_cm', 'ct.height_cm', 'dc.number as challan_number']);

        $contents = DB::table('carton_contents as cc')
            ->join('cartons as ct', 'ct.id', '=', 'cc.carton_id')
            ->join('packing_lists as pl', 'pl.id', '=', 'ct.packing_list_id')
            ->join('delivery_challans as dc', 'dc.packing_list_id', '=', 'pl.id')
            ->leftJoin('products as p', 'p.id', '=', 'cc.product_id')
            ->leftJoin('items as pi', 'pi.id', '=', 'p.item_id')
            ->leftJoin('stock_lots as sl', 'sl.id', '=', 'cc.lot_id')
            ->where('dc.export_shipment_id', $id)
            ->orderBy('ct.carton_no')
            ->get(['cc.carton_id', 'cc.colourway', 'cc.qty', 'cc.bundles', 'pi.code as product_code', 'pi.name as product_name', 'sl.lot_no'])
            ->groupBy('carton_id');

        // Where the notes carry no carton detail, the lines still say what travelled.
        $lines = DB::table('delivery_challan_lines as l')
            ->join('delivery_challans as dc', 'dc.id', '=', 'l.delivery_challan_id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('items as pi', 'pi.id', '=', 'p.item_id')
            ->where('dc.export_shipment_id', $id)
            ->orderBy('dc.id')->orderBy('l.line_no')
            ->get(['dc.number as challan_number', 'l.line_no', 'l.qty', 'l.cartons', 'pi.code as product_code', 'pi.name as product_name']);

        $document->total_cartons = $cartons->count() > 0 ? $cartons->count() : (int) $lines->sum('cartons');
        $document->total_qty = (float) $lines->sum('qty');
        $document->gross_weight_kg = $document->gross_kg ?? ($cartons->sum('gross_weight_kg') ?: null);
        $document->net_weight_kg = $document->net_kg ?? ($cartons->sum('net_weight_kg') ?: null);

        return ['document' => $document, 'cartons' => $cartons, 'contents' => $contents, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    public static function certificateOfOrigin(int $id): array
    {
        $data = self::commercialInvoice($id);
        $data['document']->origin_country = 'Bangladesh';

        return $data;
    }

    private static function document(int $id): object
    {
        $document = DB::table('export_shipments as es')
            ->leftJoin('customers as c', 'c.id', '=', 'es.customer_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'es.currency_id')
            ->leftJoin('letters_of_credit as lc', 'lc.id', '=', 'es.lc_id')
            ->where('es.id', $id)
            ->select(['es.*', 'c.name as customer_name', 'c.email as customer_email', 'c.phone as customer_phone', 'c.bin_no as customer_bin', 'c.tin_no as customer_tin',
                'cur.code as currency', 'lc.lc_no', 'lc.issuing_bank', 'lc.issued_on as lc_date'])
            ->first() ?? abort(404);

        // The consignee is the delivery address of the first note on the shipment.
        $address = DB::table('delivery_challans as dc')
            ->join('customer_addresses as ca', 'ca.id', '=', 'dc.delivery_address_id')
            ->where('dc.export_shipment_id', $id)
            ->orderBy('dc.id')
            ->first(['ca.line1 as ship_line1', 'ca.line2 as ship_line2', 'ca.city as ship_city', 'ca.district as ship_district', 'ca.postcode as ship_postcode', 'ca.country as ship_country']);

        foreach (['ship_line1', 'ship_line2', 'ship_city', 'ship_district', 'ship_postcode', 'ship_country'] as $key) {
            $document->{$key} = $address->{$key} ?? null;
        }

        $orders = DB::table('delivery_challans as dc')
            ->join('sales_orders as so', 'so.id', '=', 'dc.sales_order_id')
            ->where('dc.export_shipment_id', $id)
            ->distinct()
            ->pluck('so.customer_po_no', 'so.number');

        $document->order_numbers = $orders->keys()->filter()->implode(', ');
        $document->customer_po_numbers = $orders->values()->filter()->unique()->implode(', ');
        $document->challan_numbers = DB::table('delivery_challans')->where('export_shipment_id', $id)->orderBy('id')->pluck('number')->implode(', ');

        return $document;
    }

    /** @return Collection<int, \stdClass> */
    private static function invoices(int $id): Collection
    {
        return DB::table('sales_invoices as si')
            ->join('delivery_challans as dc', 'dc.id', '=', 'si.delivery_challan_id')
            ->where('dc.export_shipment_id', $id)
            ->where('si.status', '!=', 'cancelled')
            ->orderBy('si.id')
            ->get(['si.number', 'si.invoice_date', 'si.total']);
    }
}
