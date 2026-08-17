<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Receipt;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Support\Http\ListsResources;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * P1-5 — money in. A receipt posts on store (the GRN precedent: recording cash in hand as a
 * draft would be fiction) and allocates to invoices in the same transaction. Allocation can
 * never exceed the receipt or an invoice's outstanding balance; invoice payment statuses move
 * through the invoice state machine, not by column write.
 */
class ReceiptController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly SalesInvoiceStateMachine $invoices,
        private readonly NumberAllocator $numbers,
    ) {}

    public function index(Request $request): Response
    {
        $query = Receipt::query()->with(['customer:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'reference_no'],
            filters: ['status' => 'status', 'customer' => 'customer_id', 'method' => 'method'],
            sortable: ['number', 'receipt_date', 'amount', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Finance/Receipts/Index', [
            'receipts' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Receipt $receipt): array => [
                    ...$receipt->only(['id', 'number', 'receipt_date', 'method', 'reference_no',
                        'amount', 'allocated_amount', 'status']),
                    'customer' => $receipt->customer?->name,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'customer', 'method']),
            'openInvoices' => DB::table('sales_invoices as si')
                ->join('customers as c', 'c.id', '=', 'si.customer_id')
                ->whereIn('si.status', ['issued', 'partially_paid', 'overdue'])
                ->orderBy('si.due_date')
                ->select(['si.id', 'si.number', 'si.customer_id', 'si.currency_id', 'si.total',
                    'si.received_amount', 'si.due_date', 'c.name as customer_name'])
                // P2-1 — applied credits reduce what a receipt may allocate.
                ->selectSub(
                    DB::table('credit_notes')->whereColumn('sales_invoice_id', 'si.id')
                        ->where('status', 'applied')->selectRaw('COALESCE(SUM(amount),0)'),
                    'credited_amount',
                )
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'receipt_date' => ['required', 'date'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'cheque', 'lc', 'adjustment'])],
            'reference_no' => ['nullable', 'string', 'max:80'],
            'bank_name' => ['nullable', 'string', 'max:80'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.sales_invoice_id' => ['required', 'integer', 'exists:sales_invoices,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $receipt = DB::transaction(function () use ($data, $request): Receipt {
                $allocated = array_sum(array_column($data['allocations'], 'amount'));

                if ($allocated > (float) $data['amount'] + 0.0001) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Allocations exceed the receipt amount.',
                    ]);
                }

                /** @var Receipt $receipt */
                $receipt = Receipt::query()->create([
                    'number' => $this->numbers->next('receipt'),
                    'customer_id' => $data['customer_id'],
                    'receipt_date' => $data['receipt_date'],
                    'method' => $data['method'],
                    'reference_no' => $data['reference_no'] ?? null,
                    'bank_name' => $data['bank_name'] ?? null,
                    'currency_id' => $data['currency_id'],
                    'exchange_rate' => $data['exchange_rate'] ?? 1,
                    'amount' => $data['amount'],
                    'allocated_amount' => round($allocated, 4),
                    'status' => 'posted',
                    'created_by' => $request->user()->id,
                ]);

                foreach ($data['allocations'] as $allocation) {
                    /** @var SalesInvoice $invoice */
                    $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($allocation['sales_invoice_id']);

                    if (! in_array($invoice->status, ['issued', 'partially_paid', 'overdue'], true)) {
                        throw ValidationException::withMessages([
                            'allocations' => "Invoice {$invoice->number} is {$invoice->status} — it cannot receive money.",
                        ]);
                    }

                    if ((int) $invoice->customer_id !== (int) $data['customer_id']) {
                        throw ValidationException::withMessages([
                            'allocations' => "Invoice {$invoice->number} belongs to a different customer.",
                        ]);
                    }

                    // P2-1 — the one outstanding formula: total − received − applied credits.
                    // Recomputed under the invoice lock; a concurrent credit application
                    // serialises against this same row.
                    $outstanding = $this->invoices->outstanding($invoice);

                    if ((float) $allocation['amount'] > $outstanding + 0.0001) {
                        throw ValidationException::withMessages([
                            'allocations' => sprintf(
                                'Invoice %s has %s outstanding — cannot allocate %s.',
                                $invoice->number,
                                number_format($outstanding, 2),
                                number_format((float) $allocation['amount'], 2),
                            ),
                        ]);
                    }

                    DB::table('receipt_allocations')->insert([
                        'receipt_id' => $receipt->id,
                        'sales_invoice_id' => $invoice->id,
                        'amount' => round((float) $allocation['amount'], 4),
                    ]);

                    $invoice->forceFill([
                        'received_amount' => round((float) $invoice->received_amount + (float) $allocation['amount'], 4),
                    ])->save();

                    // Payment status derives from the money, through the machine.
                    $this->invoices->reflectPayment($invoice->refresh());
                }

                return $receipt;
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('receipts.index')
            ->with('success', "Receipt {$receipt->number} posted and allocated.");
    }
}
