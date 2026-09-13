<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\States\CreditNoteStateMachine;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * P2-1 — credit notes: the corrective document for returns, claims and rate differences.
 * Drafted by hand or auto-drafted by a challan return; approval is banded; application is
 * the only step that changes an invoice's arithmetic, under its row lock.
 */
class CreditNoteController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly CreditNoteStateMachine $states,
        private readonly SalesInvoiceStateMachine $invoices,
        private readonly \App\Modules\Finance\Services\CreditNoteApplicationService $applications,
        private readonly \App\Modules\Finance\Services\RefundService $refunds,
    ) {}

    public function index(Request $request): Response
    {
        $query = CreditNote::query()->with(['customer:id,code,name', 'currency:id,code']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: ['status' => 'status', 'reason' => 'reason', 'customer' => 'customer_id'],
            sortable: ['number', 'note_date', 'amount', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Finance/CreditNotes/Index', [
            'credit_notes' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (CreditNote $note): array => [
                    ...$note->only(['id', 'number', 'note_date', 'reason', 'amount', 'status', 'sales_invoice_id']),
                    'customer' => $note->customer?->name,
                    // BR-55 — the note is in the credited invoice's currency; say which.
                    'currency' => $note->currency?->code,
                    'invoice' => $note->sales_invoice_id
                        ? \Illuminate\Support\Facades\DB::table('sales_invoices')->where('id', $note->sales_invoice_id)->value('number')
                        : null,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'reason', 'customer']),
        ]);
    }

    /** Manual draft against an open invoice (returns auto-draft their own). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sales_invoice_id' => ['required', 'integer', 'exists:sales_invoices,id'],
            'reason' => ['required', Rule::in(['quality_claim', 'short_delivery', 'rate_difference', 'return', 'discount', 'other'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var SalesInvoice $invoice */
        $invoice = SalesInvoice::query()->findOrFail($data['sales_invoice_id']);

        if (! in_array($invoice->status, ['issued', 'partially_paid', 'overdue'], true)) {
            return back()->with('error', "Invoice {$invoice->number} is {$invoice->status} — nothing to credit against.");
        }

        // Advisory only — the binding check runs under the invoice lock at application.
        $eligible = $this->invoices->outstanding($invoice);

        if ((float) $data['amount'] > $eligible + 0.0001) {
            return back()->with('error', sprintf(
                'Invoice %s has only %s outstanding. A credit of %s would over-credit it.',
                $invoice->number,
                number_format($eligible, 2),
                number_format((float) $data['amount'], 2),
            ));
        }

        $note = CreditNote::query()->create([
            'customer_id' => $invoice->customer_id,
            'sales_invoice_id' => $invoice->id,
            'note_date' => now()->toDateString(),
            'reason' => $data['reason'],
            'currency_id' => $invoice->currency_id,
            'amount' => round((float) $data['amount'], 4),
            'status' => 'draft',
            'remarks' => $data['remarks'] ?? null,
        ]);

        return redirect()
            ->route('credit-notes.show', $note)
            ->with('success', 'Credit note drafted. Approval, then application, moves the money.');
    }

    public function show(CreditNote $creditNote): Response
    {
        $creditNote->load(['customer:id,code,name', 'currency:id,code']);

        $invoice = $creditNote->sales_invoice_id
            ? SalesInvoice::query()->find($creditNote->sales_invoice_id)
            : null;

        return Inertia::render('Finance/CreditNotes/Show', [
            'creditNote' => [
                ...$creditNote->only(['id', 'number', 'note_date', 'reason', 'amount', 'status', 'remarks', 'approved_by']),
                'customer' => $creditNote->customer?->only(['id', 'code', 'name']),
                'currency' => $creditNote->currency?->code,
            ],
            'invoice' => $invoice ? [
                ...$invoice->only(['id', 'number', 'total', 'received_amount', 'status']),
                // The invoice's own currency — the same one the note inherits, stated on the
                // invoice panel so the two figures are plainly in the same unit.
                'currency' => $invoice->currency?->code,
                'credited' => $this->invoices->appliedCredits($invoice),
                'outstanding' => $this->invoices->outstanding($invoice),
            ] : null,
            // The four figures a credit note is actually about. `applied` and `refunded` are
            // where its value went; `available` is what is left to spend. The provenance
            // invoice above says where the credit came from, which is a different question.
            'money' => [
                'amount' => (float) $creditNote->amount,
                'applied' => (float) \Illuminate\Support\Facades\DB::table('credit_note_applications')
                    ->where('credit_note_id', $creditNote->getKey())->sum('amount'),
                'refunded' => (float) \Illuminate\Support\Facades\DB::table('refunds')
                    ->where('credit_note_id', $creditNote->getKey())->where('status', 'posted')->sum('amount'),
                'available' => $this->applications->available($creditNote),
            ],
            'applications' => \Illuminate\Support\Facades\DB::table('credit_note_applications as cna')
                ->join('sales_invoices as si', 'si.id', '=', 'cna.sales_invoice_id')
                ->where('cna.credit_note_id', $creditNote->getKey())
                ->orderBy('cna.id')
                ->get(['cna.id', 'cna.amount', 'cna.applied_on', 'si.id as invoice_id', 'si.number', 'si.status']),
            'refunds' => \Illuminate\Support\Facades\DB::table('refunds')
                ->where('credit_note_id', $creditNote->getKey())
                ->orderBy('id')
                ->get(['id', 'number', 'amount', 'method', 'reference_no', 'refund_date', 'status']),
            // Only invoices that can actually take credit: same customer, same currency, and
            // something still outstanding. Offering any other would be offering a refusal.
            'targets' => \Illuminate\Support\Facades\DB::table('sales_invoices')
                ->where('customer_id', $creditNote->customer_id)
                ->where('currency_id', $creditNote->currency_id)
                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                ->orderByDesc('id')->limit(100)
                ->get(['id', 'number', 'total', 'received_amount', 'status'])
                ->map(fn (object $row): array => [
                    ...(array) $row,
                    'outstanding' => $this->invoices->outstanding(SalesInvoice::query()->find($row->id)),
                ])
                ->filter(fn (array $row): bool => $row['outstanding'] > 0.0001)
                ->values(),
            'salesReturn' => $creditNote->sales_return_id
                ? \Illuminate\Support\Facades\DB::table('sales_returns')
                    ->where('id', $creditNote->sales_return_id)
                    ->first(['id', 'number', 'returned_on', 'status'])
                : null,
            'availableTransitions' => $this->states->available($creditNote),
        ]);
    }

    /**
     * Apply part or all of this note to an invoice — which need not be the one it came from.
     * The service takes both row locks and recomputes eligibility inside them.
     */
    public function apply(Request $request, CreditNote $creditNote): RedirectResponse
    {
        $data = $request->validate([
            'sales_invoice_id' => ['required', 'integer', 'exists:sales_invoices,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $this->applications->apply(
                $creditNote,
                SalesInvoice::query()->findOrFail($data['sales_invoice_id']),
                (float) $data['amount'],
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('success', 'Credit applied.');
    }

    /** Pay part or all of this note back to the customer. */
    public function refund(Request $request, CreditNote $creditNote): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['cash', 'cheque', 'bank_transfer', 'adjustment'])],
            'reference_no' => ['nullable', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $refund = $this->refunds->post($creditNote, (float) $data['amount'], $data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('success', "Refund {$refund->number} posted.");
    }

    public function transition(Request $request, CreditNote $creditNote): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string']]);

        try {
            $this->states->transition($creditNote, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Credit note {$creditNote->refresh()->number} is now {$data['to']}.");
    }
}
