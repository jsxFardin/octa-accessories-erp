<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\Models\SalesInvoiceLine;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Support\Calculators\CostSheetCalculator;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * P1-4 — the invoice follows the challan (05-workflows §10/§11). Quantities come from what
 * was actually dispatched, rates from the order lines; nothing merely produced or packed is
 * billable. One live invoice per challan.
 */
class SalesInvoiceController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly SalesInvoiceStateMachine $states,
        private readonly CostSheetCalculator $costing,
    ) {}

    public function index(Request $request): Response
    {
        $query = SalesInvoice::query()->with(['customer:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'lc_no'],
            filters: ['status' => 'status', 'customer' => 'customer_id'],
            sortable: ['number', 'invoice_date', 'due_date', 'total', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Finance/Invoices/Index', [
            'invoices' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (SalesInvoice $invoice): array => [
                    ...$invoice->only(['id', 'number', 'invoice_date', 'due_date', 'subtotal', 'total',
                        'received_amount', 'status']),
                    'customer' => $invoice->customer?->name,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'customer']),
        ]);
    }

    /** Draft an invoice from a dispatched challan. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'delivery_challan_id' => ['required', 'integer', 'exists:delivery_challans,id'],
        ]);

        $challan = DeliveryChallan::query()->findOrFail($data['delivery_challan_id']);

        if (! in_array($challan->status, ['issued', 'in_transit', 'delivered'], true)) {
            return back()->with('error', "Only dispatched goods are billable — this challan is {$challan->status}.");
        }

        $duplicate = SalesInvoice::query()
            ->where('delivery_challan_id', $challan->id)
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($duplicate) {
            return back()->with('error', 'An invoice already exists for this challan.');
        }

        $order = DB::table('sales_orders')->where('id', $challan->sales_order_id)->first();

        if ($order === null) {
            return back()->with('error', 'This challan is not tied to a sales order; only order deliveries are invoiceable.');
        }

        $netDays = (int) (DB::table('payment_terms')->where('id', $order->payment_term_id)->value('net_days') ?? 30);

        $invoice = DB::transaction(function () use ($challan, $order, $netDays, $request): SalesInvoice {
            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::query()->create([
                'customer_id' => $challan->customer_id,
                'sales_order_id' => $challan->sales_order_id,
                'delivery_challan_id' => $challan->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays($netDays)->toDateString(),
                'currency_id' => $order->currency_id,
                'exchange_rate' => $order->exchange_rate ?? 1,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            // Billed quantity = dispatched quantity, aggregated per order line; the rate is
            // the order line's contracted rate — never re-typed at billing time.
            $lines = DB::table('delivery_challan_lines as dcl')
                ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'dcl.sales_order_line_id')
                ->where('dcl.delivery_challan_id', $challan->id)
                ->groupBy('dcl.sales_order_line_id', 'dcl.product_id', 'sol.rate_per_m', 'sol.description')
                ->get([
                    'dcl.sales_order_line_id', 'dcl.product_id', 'sol.rate_per_m', 'sol.description',
                    DB::raw('SUM(dcl.qty) as qty'),
                ]);

            $subtotal = 0.0;

            foreach ($lines as $index => $line) {
                $amount = $this->costing->lineValue((int) $line->qty, (float) ($line->rate_per_m ?? 0));
                $subtotal += $amount;

                SalesInvoiceLine::query()->create([
                    'sales_invoice_id' => $invoice->id,
                    'line_no' => $index + 1,
                    'sales_order_line_id' => $line->sales_order_line_id,
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'qty' => $line->qty,
                    'rate_per_m' => $line->rate_per_m ?? 0,
                    'tax_amount' => 0,
                    'amount' => round($amount, 4),
                ]);
            }

            $invoice->forceFill([
                'subtotal' => round($subtotal, 4),
                'tax_amount' => 0,
                'total' => round($subtotal, 4),
            ])->save();

            return $invoice;
        });

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Invoice drafted from the challan. Issue it to start the receivable.');
    }

    public function show(SalesInvoice $invoice): Response
    {
        $invoice->load('customer:id,code,name,credit_limit');

        return Inertia::render('Finance/Invoices/Show', [
            'invoice' => [
                ...$invoice->only(['id', 'number', 'invoice_date', 'due_date', 'subtotal', 'tax_amount',
                    'total', 'received_amount', 'status', 'lc_no', 'mushak_no', 'remarks',
                    'sales_order_id', 'delivery_challan_id']),
                'customer' => $invoice->customer?->only(['id', 'code', 'name']),
                // P2-1 — the one formula: total = received + credited + outstanding.
                'credited' => $this->states->appliedCredits($invoice),
                'outstanding' => $this->states->outstanding($invoice),
            ],
            'lines' => DB::table('sales_invoice_lines as sil')
                ->leftJoin('products as p', 'p.id', '=', 'sil.product_id')
                ->where('sil.sales_invoice_id', $invoice->id)
                ->orderBy('sil.line_no')
                ->get(['sil.id', 'sil.line_no', 'sil.description', 'sil.qty', 'sil.rate_per_m',
                    'sil.amount', 'p.code as product_code']),
            'creditNotes' => DB::table('credit_notes')
                ->where('sales_invoice_id', $invoice->id)
                ->orderByDesc('id')
                ->get(['id', 'number', 'note_date', 'reason', 'amount', 'status']),
            'allocations' => DB::table('receipt_allocations as ra')
                ->join('receipts as r', 'r.id', '=', 'ra.receipt_id')
                ->where('ra.sales_invoice_id', $invoice->id)
                ->get(['ra.amount', 'r.number', 'r.receipt_date', 'r.method']),
            'availableTransitions' => $this->states->available($invoice),
        ]);
    }

    public function transition(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string']]);

        try {
            $this->states->transition($invoice, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invoice {$invoice->refresh()->number} is now {$data['to']}.");
    }
}
