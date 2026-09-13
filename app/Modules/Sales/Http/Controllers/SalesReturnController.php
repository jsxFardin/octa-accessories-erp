<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Modules\Sales\States\SalesReturnStateMachine;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer returns of delivered, invoiced goods.
 *
 * The screen's job is to make one thing unmistakable: the invoice is not being reopened. It is
 * shown, with its status, as the document the goods were billed on — and the return, the stock
 * movement and the credit note that follow are all separate records with their own lives.
 */
class SalesReturnController extends Controller
{
    use ListsResources;

    public function __construct(private readonly SalesReturnStateMachine $states) {}

    public function index(Request $request): Response
    {
        $query = SalesReturn::query()->with(['salesInvoice:id,number,status'])->withCount('lines');

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'reason'],
            filters: ['status' => 'status', 'customer' => 'customer_id'],
            sortable: ['number', 'returned_on', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Sales/SalesReturns/Index', [
            'sales_returns' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (SalesReturn $return): array => [
                    ...$return->only(['id', 'number', 'returned_on', 'reason', 'status', 'lines_count']),
                    'invoice' => $return->salesInvoice?->only(['id', 'number', 'status']),
                    'customer' => DB::table('customers')->where('id', $return->customer_id)->value('name'),
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'customer']),
        ]);
    }

    /**
     * Only invoices that billed something can be returned against, and `paid` is deliberately
     * among them — that is the case the whole feature exists for.
     */
    public function create(Request $request): Response
    {
        $invoices = DB::table('sales_invoices as si')
            ->join('customers as c', 'c.id', '=', 'si.customer_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'si.currency_id')
            ->whereIn('si.status', SalesReturnStateMachine::RETURNABLE_INVOICE_STATUSES)
            ->whereNotNull('si.number')
            ->orderByDesc('si.id')
            ->limit(200)
            ->get([
                'si.id', 'si.number', 'si.status', 'si.invoice_date', 'si.total',
                'si.customer_id', 'si.delivery_challan_id',
                'c.name as customer_name', 'cur.code as currency',
            ]);

        return Inertia::render('Sales/SalesReturns/Form', [
            'invoices' => $invoices,
            'warehouses' => DB::table('warehouses')->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'kind']),
            'preselectInvoiceId' => $request->integer('invoice') ?: null,
        ]);
    }

    /** The returnable balance per line of one invoice, for the form to draw against. */
    public function lines(SalesInvoice $invoice): Response
    {
        return Inertia::render('Sales/SalesReturns/Form', [
            'invoice' => $invoice->only(['id', 'number', 'status', 'customer_id', 'delivery_challan_id']),
            'lines' => $this->returnableLines($invoice),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sales_invoice_id' => ['required', 'integer', 'exists:sales_invoices,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'returned_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_invoice_line_id' => ['required', 'integer', 'exists:sales_invoice_lines,id'],
            'lines.*.lot_id' => ['nullable', 'integer', 'exists:stock_lots,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        /** @var SalesInvoice $invoice */
        $invoice = SalesInvoice::query()->findOrFail($data['sales_invoice_id']);

        $return = DB::transaction(function () use ($data, $invoice, $request): SalesReturn {
            $return = SalesReturn::query()->create([
                'sales_invoice_id' => $invoice->getKey(),
                // Carried from the invoice rather than posted by the form: the challan is a
                // fact about the shipment, not a choice the person raising the return makes.
                'delivery_challan_id' => $invoice->delivery_challan_id,
                'customer_id' => $invoice->customer_id,
                'warehouse_id' => $data['warehouse_id'],
                'returned_on' => $data['returned_on'],
                'reason' => $data['reason'],
                'status' => SalesReturn::DRAFT,
                'created_by' => $request->user()?->id,
            ]);

            foreach (array_values($data['lines']) as $index => $line) {
                // `exists:sales_invoice_lines,id` has already vouched for the id, so this row
                // is there; `firstOrFail` says so rather than leaving a null the guard below
                // would have to pretend to handle.
                $invoiceLine = DB::table('sales_invoice_lines')->where('id', $line['sales_invoice_line_id'])
                    ->firstOrFail(['product_id', 'rate_per_m']);

                SalesReturnLine::query()->create([
                    'sales_return_id' => $return->getKey(),
                    'line_no' => $index + 1,
                    'sales_invoice_line_id' => $line['sales_invoice_line_id'],
                    'product_id' => $invoiceLine->product_id,
                    'lot_id' => $line['lot_id'] ?? null,
                    'qty' => $line['qty'],
                    // Snapshotted from the invoice line, so the credit that follows is worth
                    // what was charged rather than what the price list says today (Q1).
                    'rate_per_m' => $invoiceLine->rate_per_m,
                ]);
            }

            return $return;
        });

        return redirect()
            ->route('sales-returns.show', $return)
            ->with('success', 'Return saved as a draft.');
    }

    public function show(SalesReturn $salesReturn): Response
    {
        $salesReturn->load(['salesInvoice:id,number,status,total,received_amount,currency_id', 'lines']);

        return Inertia::render('Sales/SalesReturns/Show', [
            'salesReturn' => [
                ...$salesReturn->only(['id', 'number', 'returned_on', 'reason', 'status',
                    'warehouse_id', 'customer_id', 'delivery_challan_id']),
                'customer' => DB::table('customers')->where('id', $salesReturn->customer_id)->value('name'),
                'warehouse' => DB::table('warehouses')->where('id', $salesReturn->warehouse_id)->value('name'),
            ],
            // Shown with its status so nobody reads this screen as the invoice being reopened.
            'invoice' => $salesReturn->salesInvoice?->only(['id', 'number', 'status', 'total', 'received_amount']),
            'lines' => DB::table('sales_return_lines as srl')
                ->leftJoin('sales_invoice_lines as sil', 'sil.id', '=', 'srl.sales_invoice_line_id')
                ->leftJoin('products as p', 'p.id', '=', 'srl.product_id')
                ->leftJoin('stock_lots as sl', 'sl.id', '=', 'srl.lot_id')
                ->where('srl.sales_return_id', $salesReturn->getKey())
                ->orderBy('srl.line_no')
                ->get([
                    'srl.id', 'srl.line_no', 'srl.qty', 'srl.rate_per_m',
                    'p.code as product_code', 'sl.lot_no', 'sl.status as lot_status',
                    'sil.line_no as invoice_line_no', 'sil.qty as invoiced_qty', 'sil.returned_qty',
                ]),
            'creditNotes' => DB::table('credit_notes')
                ->where('sales_return_id', $salesReturn->getKey())
                ->get(['id', 'number', 'amount', 'status', 'reason']),
            'movements' => DB::table('stock_ledger')
                ->where('source_type', SalesReturn::class)
                ->where('source_id', $salesReturn->getKey())
                ->get(['id', 'lot_id', 'qty', 'unit_cost', 'value', 'occurred_at']),
            'availableTransitions' => $this->states->available($salesReturn),
        ]);
    }

    public function transition(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->states->transition($salesReturn, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Return moved to {$data['to']}.");
    }

    /**
     * What each line of the invoice still has to give back, and the lots it was shipped on.
     *
     * @return list<array<string, mixed>>
     */
    private function returnableLines(SalesInvoice $invoice): array
    {
        $lots = $invoice->delivery_challan_id === null
            ? collect()
            : DB::table('stock_ledger as sle')
                ->join('stock_lots as sl', 'sl.id', '=', 'sle.lot_id')
                ->where('sle.source_type', \App\Modules\Dispatch\Models\DeliveryChallan::class)
                ->where('sle.source_id', $invoice->delivery_challan_id)
                ->where('sle.movement_type', 'dispatch')
                ->groupBy('sl.id', 'sl.lot_no', 'sl.product_id')
                ->selectRaw('sl.id, sl.lot_no, sl.product_id, SUM(ABS(sle.qty)) AS shipped')
                ->get();

        return DB::table('sales_invoice_lines as sil')
            ->leftJoin('products as p', 'p.id', '=', 'sil.product_id')
            ->where('sil.sales_invoice_id', $invoice->getKey())
            ->orderBy('sil.line_no')
            ->get(['sil.id', 'sil.line_no', 'sil.description', 'sil.qty', 'sil.returned_qty',
                'sil.rate_per_m', 'sil.product_id', 'p.code as product_code'])
            ->map(fn (object $line): array => [
                ...(array) $line,
                'returnable' => round((float) $line->qty - (float) $line->returned_qty, 6),
                'lots' => $lots->where('product_id', $line->product_id)->values()->all(),
            ])
            ->all();
    }
}
