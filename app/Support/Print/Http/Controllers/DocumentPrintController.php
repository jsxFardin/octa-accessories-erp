<?php

declare(strict_types=1);

namespace App\Support\Print\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\Organisation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Printable documents.
 *
 * A quotation, a purchase order and a job card all end up in someone else's hands — a customer,
 * a supplier, a machine operator — and until now the only way to produce one was to print the
 * application's own screen, sidebar and all.
 *
 * These are Blade rather than Inertia on purpose: a print view wants no JavaScript, no shell
 * and no client-side formatting. It is a page the browser can render straight to paper or PDF,
 * which is also why there is no PDF library here — the browser already has one, and a
 * dependency that renders slightly different output from what the screen showed is a support
 * problem waiting to happen.
 *
 * Printing is an audited event: a quotation leaving the building is worth the same row in the
 * log as an export (the `printed` event exists in `audit_logs_event_chk` for exactly this).
 */
class DocumentPrintController extends Controller
{
    public function __construct(
        private readonly Organisation $organisation,
        private readonly AuditLogger $audit,
    ) {}

    public function quotation(Request $request, int $quotation): View
    {
        abort_unless($request->user()?->hasPermission('quotation.view'), 403);

        $document = DB::table('quotations as q')
            ->leftJoin('customers as c', 'c.id', '=', 'q.customer_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'q.currency_id')
            ->leftJoin('payment_terms as pt', 'pt.id', '=', 'q.payment_term_id')
            ->where('q.id', $quotation)
            ->select([
                'q.*', 'c.name as customer_name', 'c.email as customer_email', 'c.phone as customer_phone',
                'cur.code as currency', 'pt.name as payment_terms',
            ])
            ->first() ?? abort(404);

        $lines = DB::table('quotation_lines as l')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.quotation_id', $quotation)
            ->orderBy('l.line_no')
            ->get(['l.line_no', 'l.description', 'l.qty', 'l.rate_per_m', 'l.tooling_charge', 'l.line_total', 'l.lead_time_days', 'p.code as product_code']);

        $this->audit->recordTable('quotations', $quotation, 'printed');

        return view('print.quotation', [
            'organisation' => $this->organisation->forFrontend(),
            'document' => $document,
            'lines' => $lines,
        ]);
    }

    public function purchaseOrder(Request $request, int $purchaseOrder): View
    {
        abort_unless($request->user()?->hasPermission('purchase_order.view'), 403);

        $document = DB::table('purchase_orders as po')
            ->leftJoin('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'po.currency_id')
            ->leftJoin('payment_terms as pt', 'pt.id', '=', 'po.payment_term_id')
            ->leftJoin('factory_units as fu', 'fu.id', '=', 'po.factory_unit_id')
            ->where('po.id', $purchaseOrder)
            ->select([
                'po.*', 's.name as supplier_name', 's.address as supplier_address', 's.country as supplier_country',
                's.email as supplier_email', 's.phone as supplier_phone',
                'cur.code as currency', 'pt.name as payment_terms', 'fu.name as unit_name', 'fu.address as unit_address',
            ])
            ->first() ?? abort(404);

        // An unapproved order is not a document anyone should be holding: printing one is how a
        // supplier ends up shipping against a price nobody signed off (06-rbac §5).
        abort_if(
            in_array($document->status, ['draft', 'pending_approval'], true),
            403,
            'This order has not been approved yet, so it cannot be printed.',
        );

        $lines = DB::table('purchase_order_lines as l')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'l.uom_id')
            ->where('l.po_id', $purchaseOrder)
            ->orderBy('l.line_no')
            ->get(['l.line_no', 'i.code as item_code', 'i.name as item_name', 'l.qty', 'u.code as uom', 'l.rate', 'l.amount', 'l.expected_date', 'l.cert_claim']);

        $this->audit->recordTable('purchase_orders', $purchaseOrder, 'printed');

        return view('print.purchase-order', [
            'organisation' => $this->organisation->forFrontend(),
            'document' => $document,
            'lines' => $lines,
        ]);
    }

    public function jobCard(Request $request, int $jobCard): View
    {
        abort_unless($request->user()?->hasPermission('job_card.view'), 403);

        $document = DB::table('job_cards as j')
            ->leftJoin('products as p', 'p.id', '=', 'j.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('artwork_versions as av', 'av.id', '=', 'j.artwork_version_id')
            ->leftJoin('artworks as a', 'a.id', '=', 'av.artwork_id')
            ->leftJoin('factory_units as fu', 'fu.id', '=', 'j.factory_unit_id')
            ->where('j.id', $jobCard)
            ->select([
                'j.*', 'p.code as product_code', 'p.name as product_name', 'p.product_type',
                'c.name as customer_name', 'a.code as artwork_code', 'av.version_no as artwork_version',
                'av.approved_at as artwork_approved_at', 'fu.name as unit_name',
            ])
            ->first() ?? abort(404);

        $operations = DB::table('job_card_operations as o')
            ->leftJoin('machine_groups as mg', 'mg.id', '=', 'o.machine_group_id')
            ->where('o.job_card_id', $jobCard)
            ->orderBy('o.sequence_no')
            ->get(['o.sequence_no', 'o.code', 'o.name', 'mg.name as machine_group', 'o.planned_minutes', 'o.status']);

        $this->audit->recordTable('job_cards', $jobCard, 'printed');

        return view('print.job-card', [
            'organisation' => $this->organisation->forFrontend(),
            'document' => $document,
            'operations' => $operations,
        ]);
    }
}
