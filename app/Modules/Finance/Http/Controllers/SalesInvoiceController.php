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
use App\Support\Text\Plain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
        $query = SalesInvoice::query()->with(['customer:id,code,name', 'currency:id,code']);

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
                    'currency' => $invoice->currency?->code,
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
            return back()->with('error', 'Only dispatched goods are billable — this delivery note is '.Plain::status($challan->status).'.');
        }

        $duplicate = SalesInvoice::query()
            ->where('delivery_challan_id', $challan->id)
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($duplicate) {
            return back()->with('error', 'An invoice already exists for this delivery note.');
        }

        $order = DB::table('sales_orders')->where('id', $challan->sales_order_id)->first();

        if ($order === null) {
            return back()->with('error', 'This delivery note is not tied to a sales order; only order deliveries are invoiceable.');
        }

        // The order's terms, then the customer's, and only then a default — an order created
        // before terms were carried forward must not silently become Net 30.
        $termId = $order->payment_term_id
            ?? DB::table('customers')->where('id', $challan->customer_id)->value('payment_term_id');

        $netDays = (int) (DB::table('payment_terms')->where('id', $termId)->value('net_days') ?? 30);

        // The export credit covering the order, when there is a live one: the invoice draws on it.
        $credit = DB::table('lc_sales_orders as lso')
            ->join('letters_of_credit as lc', 'lc.id', '=', 'lso.lc_id')
            ->where('lso.sales_order_id', $challan->sales_order_id)
            ->whereIn('lc.status', ['advised', 'accepted', 'shipped', 'presented', 'discrepant'])
            ->orderByDesc('lc.id')
            ->first(['lc.id', 'lc.lc_no']);

        $invoice = DB::transaction(function () use ($challan, $order, $netDays, $request, $credit): SalesInvoice {
            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::query()->create([
                'customer_id' => $challan->customer_id,
                'sales_order_id' => $challan->sales_order_id,
                'delivery_challan_id' => $challan->id,
                'lc_id' => $credit?->id,
                'lc_no' => $credit?->lc_no,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays($netDays)->toDateString(),
                'currency_id' => $order->currency_id,
                'exchange_rate' => $order->exchange_rate ?? 1,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            // Billed quantity = dispatched quantity, aggregated per order line; the rate is
            // the order line's contracted rate — never re-typed at billing time.
            // FN-1 AC3 — the tax is the order line's tax code (VAT 15 % domestic, zero-rated
            // export), never typed at billing time. It was hard-coded to zero, so every
            // domestic invoice under-billed by the VAT and no Mushak could be reconciled.
            $lines = DB::table('delivery_challan_lines as dcl')
                ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'dcl.sales_order_line_id')
                ->leftJoin('taxes as t', 't.id', '=', 'sol.tax_id')
                ->where('dcl.delivery_challan_id', $challan->id)
                ->groupBy('dcl.sales_order_line_id', 'dcl.product_id', 'sol.rate_per_m', 'sol.description', 'sol.tax_id', 't.rate_pct')
                ->get([
                    'dcl.sales_order_line_id', 'dcl.product_id', 'sol.rate_per_m', 'sol.description',
                    'sol.tax_id', 't.rate_pct as tax_rate_pct',
                    DB::raw('SUM(dcl.qty) as qty'),
                ]);

            $subtotal = 0.0;
            $tax = 0.0;

            foreach ($lines as $index => $line) {
                $amount = $this->costing->lineValue((int) $line->qty, (float) ($line->rate_per_m ?? 0));
                $lineTax = round($amount * (float) ($line->tax_rate_pct ?? 0) / 100, 4);
                $subtotal += $amount;
                $tax += $lineTax;

                SalesInvoiceLine::query()->create([
                    'sales_invoice_id' => $invoice->id,
                    'line_no' => $index + 1,
                    'sales_order_line_id' => $line->sales_order_line_id,
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'qty' => $line->qty,
                    'rate_per_m' => $line->rate_per_m ?? 0,
                    'tax_id' => $line->tax_id,
                    'tax_amount' => $lineTax,
                    'amount' => round($amount, 4),
                ]);
            }

            $invoice->forceFill([
                'subtotal' => round($subtotal, 4),
                'tax_amount' => round($tax, 4),
                'total' => round($subtotal + $tax, 4),
            ])->save();

            return $invoice;
        });

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Invoice drafted from the delivery note. Issue it to start the receivable.');
    }

    public function show(SalesInvoice $invoice): Response
    {
        $invoice->load(['customer:id,code,name,credit_limit', 'currency:id,code,name,symbol']);

        return Inertia::render('Finance/Invoices/Show', [
            'invoice' => [
                ...$invoice->only(['id', 'number', 'invoice_date', 'due_date', 'subtotal', 'tax_amount',
                    'total', 'received_amount', 'status', 'lc_id', 'lc_no', 'mushak_no', 'remarks',
                    'sales_order_id', 'delivery_challan_id', 'exchange_rate']),
                'customer' => $invoice->customer?->only(['id', 'code', 'name']),
                // BR-47 — most invoices on this system are raised in USD. Rendering them
                // against the base currency labelled every one of them BDT, which is not an
                // omission but a wrong number on a document someone is going to be paid on.
                'currency' => $invoice->currency?->only(['id', 'code', 'name', 'symbol']),
                // P2-1 — the one formula: total = received + credited + outstanding.
                'credited' => $this->states->appliedCredits($invoice),
                'outstanding' => $this->states->outstanding($invoice),
            ],
            'lines' => DB::table('sales_invoice_lines as sil')
                ->leftJoin('products as p', 'p.id', '=', 'sil.product_id')
                ->leftJoin('items as pi', 'pi.id', '=', 'p.item_id')
                ->where('sil.sales_invoice_id', $invoice->id)
                ->orderBy('sil.line_no')
                ->get(['sil.id', 'sil.line_no', 'sil.description', 'sil.qty', 'sil.rate_per_m',
                    'sil.tax_amount', 'sil.amount', 'pi.code as product_code']),
            'creditNotes' => DB::table('credit_notes')
                ->where('sales_invoice_id', $invoice->id)
                ->orderByDesc('id')
                ->get(['id', 'number', 'note_date', 'reason', 'amount', 'status']),
            'allocations' => DB::table('receipt_allocations as ra')
                ->join('receipts as r', 'r.id', '=', 'ra.receipt_id')
                ->where('ra.sales_invoice_id', $invoice->id)
                ->get(['ra.amount', 'r.number', 'r.receipt_date', 'r.method']),
            'availableTransitions' => $this->states->available($invoice),
            // The customer's export credits an invoice can draw on, in the invoice's currency.
            'exportLcs' => DB::table('letters_of_credit')
                ->where('direction', 'export')
                ->where('customer_id', $invoice->customer_id)
                ->where('currency_id', $invoice->currency_id)
                ->where(fn ($q) => $q->whereIn('status', ['advised', 'accepted', 'shipped', 'presented', 'discrepant'])->orWhere('id', $invoice->lc_id ?? 0))
                ->orderByDesc('id')
                ->get(['id', 'number', 'lc_no', 'status', 'amount', 'expiry_date']),
            // FN-3 AC2 — the NCRs a quality claim on this invoice can point at: open or
            // recent ones against this customer's jobs, newest first.
            'ncrs' => DB::table('ncrs as n')
                ->leftJoin('job_cards as jc', 'jc.id', '=', 'n.job_card_id')
                ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'jc.sales_order_line_id')
                ->leftJoin('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
                ->where(fn ($q) => $q->where('so.customer_id', $invoice->customer_id)->orWhereNull('n.job_card_id'))
                ->orderByDesc('n.id')
                ->limit(50)
                ->get(['n.id', 'n.number', 'n.status', 'n.severity']),
        ]);
    }

    /**
     * FN-1 AC3 — the references that arrive after issue: the Mushak (VAT challan) number the
     * authority assigns, the LC the shipment is drawn under, a remark. An issued invoice's
     * figures are immutable (NFR-16); these are its paperwork, not its money.
     */
    public function updateReferences(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        if ($invoice->status === 'cancelled') {
            return back()->with('error', 'A cancelled invoice takes no references.');
        }

        $data = $request->validate([
            'mushak_no' => ['nullable', 'string', 'max:60'],
            // The credit, not a typed number: the bank number is copied from it.
            'lc_id' => ['nullable', 'integer', Rule::exists('letters_of_credit', 'id')->where('direction', 'export')->where('customer_id', $invoice->customer_id)],
            'lc_no' => ['nullable', 'string', 'max:60'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], [
            'lc_id.exists' => "That credit is not one of this customer's export LCs.",
        ]);

        if (array_key_exists('lc_id', $data)) {
            $data['lc_no'] = $data['lc_id'] === null
                ? ($data['lc_no'] ?? null)
                : DB::table('letters_of_credit')->where('id', $data['lc_id'])->value('lc_no');
        }

        // Auditable on the model records old and new.
        $invoice->fill($data)->save();

        return back()->with('success', 'Invoice references saved.');
    }

    public function transition(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string']]);

        try {
            $this->states->transition($invoice, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invoice {$invoice->refresh()->number} is now ".Plain::status($data['to']).'.');
    }
}
