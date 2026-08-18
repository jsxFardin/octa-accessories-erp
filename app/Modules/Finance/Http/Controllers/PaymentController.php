<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Payment;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\States\SupplierBillStateMachine;
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
 * FN-5 — pay a supplier. A payment posts on store and allocates to approved bills in the
 * same transaction. Allocation cannot exceed the payment or a bill's outstanding balance;
 * bill payment statuses move through the bill state machine.
 */
class PaymentController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly SupplierBillStateMachine $bills,
        private readonly NumberAllocator $numbers,
    ) {}

    public function index(Request $request): Response
    {
        $query = Payment::query()->with(['supplier:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'reference_no'],
            filters: ['status' => 'status', 'supplier' => 'supplier_id', 'method' => 'method'],
            sortable: ['number', 'payment_date', 'amount', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Finance/Payments/Index', [
            'payments' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Payment $payment): array => [
                    ...$payment->only(['id', 'number', 'payment_date', 'method', 'reference_no',
                        'amount', 'allocated_amount', 'status']),
                    'supplier' => $payment->supplier?->name,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'supplier', 'method']),
            'openBills' => DB::table('supplier_bills as sb')
                ->join('suppliers as s', 's.id', '=', 'sb.supplier_id')
                ->whereIn('sb.status', ['approved', 'partially_paid'])
                ->orderBy('sb.due_date')
                ->select(['sb.id', 'sb.number', 'sb.bill_no', 'sb.supplier_id', 'sb.currency_id',
                    'sb.total', 'sb.paid_amount', 'sb.due_date', 's.name as supplier_name'])
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', Rule::in(['bank_transfer', 'cash', 'cheque', 'lc', 'adjustment'])],
            'reference_no' => ['nullable', 'string', 'max:80'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.supplier_bill_id' => ['required', 'integer', 'exists:supplier_bills,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $payment = DB::transaction(function () use ($data, $request): Payment {
                $allocated = array_sum(array_column($data['allocations'], 'amount'));

                if ($allocated > (float) $data['amount'] + 0.0001) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Allocations exceed the payment amount.',
                    ]);
                }

                /** @var Payment $payment */
                $payment = new Payment;
                $payment->forceFill([
                    'number' => $this->numbers->next('payment'),
                    'supplier_id' => $data['supplier_id'],
                    'payment_date' => $data['payment_date'],
                    'method' => $data['method'],
                    'reference_no' => $data['reference_no'] ?? null,
                    'currency_id' => $data['currency_id'],
                    'exchange_rate' => $data['exchange_rate'] ?? 1,
                    'amount' => $data['amount'],
                    'allocated_amount' => round($allocated, 4),
                    'status' => 'posted',
                    'remarks' => $data['remarks'] ?? null,
                    'created_by' => $request->user()->id,
                ])->save();

                foreach ($data['allocations'] as $allocation) {
                    /** @var SupplierBill $bill */
                    $bill = SupplierBill::query()->lockForUpdate()->findOrFail($allocation['supplier_bill_id']);

                    if (! in_array($bill->status, ['approved', 'partially_paid'], true)) {
                        throw ValidationException::withMessages([
                            'allocations' => "Bill {$bill->number} is {$bill->status} — it cannot receive payment.",
                        ]);
                    }

                    if ((int) $bill->supplier_id !== (int) $data['supplier_id']) {
                        throw ValidationException::withMessages([
                            'allocations' => "Bill {$bill->number} belongs to a different supplier.",
                        ]);
                    }

                    $outstanding = $this->bills->outstanding($bill);

                    if ((float) $allocation['amount'] > $outstanding + 0.0001) {
                        throw ValidationException::withMessages([
                            'allocations' => sprintf(
                                'Bill %s has %s outstanding — cannot allocate %s.',
                                $bill->number ?? $bill->bill_no,
                                number_format($outstanding, 2),
                                number_format((float) $allocation['amount'], 2),
                            ),
                        ]);
                    }

                    DB::table('payment_allocations')->insert([
                        'payment_id' => $payment->id,
                        'supplier_bill_id' => $bill->id,
                        'amount' => round((float) $allocation['amount'], 4),
                    ]);

                    $bill->forceFill([
                        'paid_amount' => round((float) $bill->paid_amount + (float) $allocation['amount'], 4),
                    ])->save();

                    $this->bills->reflectPayment($bill->refresh());
                }

                return $payment;
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('payments.index')
            ->with('success', "Payment {$payment->number} posted and allocated.");
    }
}
