<?php

declare(strict_types=1);

namespace App\Modules\Trade\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Trade\Models\LetterOfCredit;
use App\Support\Http\ListsResources;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Letters of credit, and the payment terms that stand in for them.
 *
 * Yarn, ribbon and ink come from the UK, Turkey, China, Hong Kong and India (00-overview §2),
 * so nearly every raw material order passes through a credit. Two dates on this screen are the
 * ones that cost money when missed: `last_shipment_date`, after which the supplier cannot ship
 * against the credit, and `expiry_date`, after which the bank will not pay. Both are surfaced
 * on the list as a countdown rather than as a column somebody has to compare against today.
 */
class LetterOfCreditController extends Controller
{
    use ListsResources;

    /** An LC that has left draft is a commitment; only these fields still move. */
    private const EDITABLE_AFTER_DRAFT = ['lc_no', 'issued_on', 'remarks', 'bank_account_id', 'charges_amount'];

    public function __construct(private readonly NumberAllocator $numbers) {}

    public function index(Request $request): Response
    {
        $query = LetterOfCredit::query()->with(['supplier:id,code,name', 'bankAccount:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'lc_no'],
            filters: ['status' => 'status', 'supplier' => 'supplier_id', 'kind' => 'kind'],
            sortable: ['number', 'issued_on', 'expiry_date', 'amount', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Trade/LettersOfCredit/Index', [
            'letters' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (LetterOfCredit $lc): array => [
                    ...$lc->only(['id', 'number', 'lc_no', 'kind', 'amount', 'status',
                        'issued_on', 'expiry_date', 'last_shipment_date']),
                    'supplier' => $lc->supplier?->name,
                    'bank' => $lc->bankAccount?->name,
                    'currency' => $lc->currency_id,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'supplier', 'kind']),
            'suppliers' => DB::table('suppliers')->orderBy('name')->get(['id', 'name']),
            'kinds' => LetterOfCredit::KINDS,
            'statuses' => LetterOfCredit::STATUSES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Trade/LettersOfCredit/Form', ['letter' => null, ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $letter = DB::transaction(function () use ($data, $request): LetterOfCredit {
            return LetterOfCredit::query()->create([
                ...$data,
                // BR-34 — the number is allocated inside the transaction that writes the row.
                'number' => $this->numbers->next('letter_of_credit'),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
        });

        return redirect()->route('letters-of-credit.show', $letter)
            ->with('success', "Letter of credit {$letter->number} saved as a draft.");
    }

    public function show(LetterOfCredit $letterOfCredit): Response
    {
        $letterOfCredit->load(['supplier', 'bankAccount', 'amendments' => fn ($q) => $q->orderBy('amendment_no')]);

        return Inertia::render('Trade/LettersOfCredit/Show', [
            'letter' => [
                ...$letterOfCredit->toArray(),
                'supplier_name' => $letterOfCredit->supplier?->name,
                'bank_name' => $letterOfCredit->bankAccount?->name,
                'current_amount' => $letterOfCredit->currentAmount(),
                'effective_expiry' => $letterOfCredit->effectiveExpiry(),
            ],
            'amendments' => $letterOfCredit->amendments,
            'purchaseOrders' => DB::table('lc_purchase_orders as lpo')
                ->join('purchase_orders as po', 'po.id', '=', 'lpo.po_id')
                ->where('lpo.lc_id', $letterOfCredit->id)
                ->orderBy('po.number')
                ->get(['po.id', 'po.number', 'po.order_date', 'po.total', 'po.status', 'lpo.covered_amount']),
            'shipments' => DB::table('import_shipments')
                ->where('lc_id', $letterOfCredit->id)
                ->orderByDesc('id')
                ->get(['id', 'number', 'invoice_no', 'transport_doc_no', 'eta', 'status']),
            // Only the orders that could still be covered: same supplier, not cancelled, and
            // not already attached to this credit.
            'availablePurchaseOrders' => DB::table('purchase_orders')
                ->where('supplier_id', $letterOfCredit->supplier_id)
                ->whereNotIn('status', ['cancelled', 'closed'])
                ->whereNotIn('id', fn ($q) => $q->from('lc_purchase_orders')
                    ->where('lc_id', $letterOfCredit->id)->select('po_id'))
                ->orderByDesc('id')
                ->get(['id', 'number', 'total']),
            'statuses' => LetterOfCredit::STATUSES,
        ]);
    }

    public function edit(LetterOfCredit $letterOfCredit): Response
    {
        return Inertia::render('Trade/LettersOfCredit/Form', [
            'letter' => $letterOfCredit,
            ...$this->options(),
        ]);
    }

    public function update(Request $request, LetterOfCredit $letterOfCredit): RedirectResponse
    {
        $data = $this->validated($request, $letterOfCredit);

        // Past draft, the commercial terms belong to the bank, not to this screen: an amount
        // or an expiry that changes without an amendment row is a figure that reconciles
        // against nothing.
        if ($letterOfCredit->status !== 'draft') {
            $data = array_intersect_key($data, array_flip(self::EDITABLE_AFTER_DRAFT));
        }

        $letterOfCredit->update($data);

        return redirect()->route('letters-of-credit.show', $letterOfCredit)
            ->with('success', "Letter of credit {$letterOfCredit->number} updated.");
    }

    /**
     * Move the credit along its lifecycle.
     *
     * A short, explicit ladder rather than a state machine class: an LC has no guards worth
     * expressing beyond the order of its states, and the bank owns the real workflow.
     */
    public function transition(Request $request, LetterOfCredit $letterOfCredit): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(LetterOfCredit::STATUSES)],
            'lc_no' => ['nullable', 'string', 'max:60'],
            'issued_on' => ['nullable', 'date'],
        ]);

        $allowed = match ($letterOfCredit->status) {
            'draft' => ['applied', 'cancelled'],
            'applied' => ['opened', 'cancelled'],
            'opened' => ['shipped', 'closed', 'cancelled'],
            'shipped' => ['retired', 'closed'],
            'retired' => ['closed'],
            default => [],
        };

        abort_unless(in_array($data['status'], $allowed, true), 422, "An LC cannot go from {$letterOfCredit->status} to {$data['status']}.");

        // Opening is the moment the bank's own number exists; without it the credit cannot be
        // matched to a shipment document, so it is required here rather than hoped for later.
        if ($data['status'] === 'opened') {
            abort_if(($data['lc_no'] ?? $letterOfCredit->lc_no) === null, 422, "The bank's LC number is needed to mark this credit open.");
        }

        $letterOfCredit->update(array_filter([
            'status' => $data['status'],
            'lc_no' => $data['lc_no'] ?? $letterOfCredit->lc_no,
            'issued_on' => $data['issued_on'] ?? ($data['status'] === 'opened' ? now()->toDateString() : $letterOfCredit->issued_on?->toDateString()),
        ], fn ($value): bool => $value !== null));

        return back()->with('success', "Letter of credit {$letterOfCredit->number} is now {$data['status']}.");
    }

    /** Attach a purchase order to the credit that pays for it. */
    public function attachOrder(Request $request, LetterOfCredit $letterOfCredit): RedirectResponse
    {
        $data = $request->validate([
            'po_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'covered_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $order = DB::table('purchase_orders')->where('id', $data['po_id'])->first();

        abort_unless($order !== null && (int) $order->supplier_id === $letterOfCredit->supplier_id, 422,
            'That order is for a different supplier than the credit.');

        DB::table('lc_purchase_orders')->updateOrInsert(
            ['lc_id' => $letterOfCredit->id, 'po_id' => $data['po_id']],
            ['covered_amount' => $data['covered_amount'] ?? $order->total],
        );

        return back()->with('success', "Order {$order->number} is covered by this credit.");
    }

    public function detachOrder(LetterOfCredit $letterOfCredit, int $poId): RedirectResponse
    {
        DB::table('lc_purchase_orders')->where('lc_id', $letterOfCredit->id)->where('po_id', $poId)->delete();

        return back()->with('success', 'Order removed from this credit.');
    }

    /** Record an amendment — more money, a later date, and what the bank charged for it. */
    public function amend(Request $request, LetterOfCredit $letterOfCredit): RedirectResponse
    {
        abort_unless(in_array($letterOfCredit->status, ['applied', 'opened', 'shipped'], true), 422,
            'Only a live credit can be amended.');

        $data = $request->validate([
            'amended_on' => ['required', 'date'],
            'amount_delta' => ['numeric'],
            'new_expiry_date' => ['nullable', 'date'],
            'new_last_shipment_date' => ['nullable', 'date', 'before_or_equal:new_expiry_date'],
            'charges_amount' => ['numeric', 'min:0'],
            'narrative' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($data, $letterOfCredit, $request): void {
            $next = (int) DB::table('lc_amendments')->where('lc_id', $letterOfCredit->id)
                ->lockForUpdate()->max('amendment_no') + 1;

            DB::table('lc_amendments')->insert([
                'lc_id' => $letterOfCredit->id,
                'amendment_no' => $next,
                'amended_on' => $data['amended_on'],
                'amount_delta' => $data['amount_delta'] ?? 0,
                'new_expiry_date' => $data['new_expiry_date'] ?? null,
                'new_last_shipment_date' => $data['new_last_shipment_date'] ?? null,
                'charges_amount' => $data['charges_amount'] ?? 0,
                'narrative' => $data['narrative'] ?? null,
                'created_at' => now(),
                'created_by' => $request->user()->id,
            ]);

            // The dates move on the credit as well, because every other screen reads them
            // from there; the amendment row keeps the history of the move.
            $letterOfCredit->forceFill(array_filter([
                'expiry_date' => $data['new_expiry_date'] ?? null,
                'last_shipment_date' => $data['new_last_shipment_date'] ?? null,
            ]))->save();
        });

        return back()->with('success', 'Amendment recorded.');
    }

    public function destroy(LetterOfCredit $letterOfCredit): RedirectResponse
    {
        abort_unless($letterOfCredit->status === 'draft', 422, 'Only a draft credit can be deleted; cancel the others.');

        $letterOfCredit->delete();

        return redirect()->route('letters-of-credit.index')->with('success', 'Draft credit deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?LetterOfCredit $letter = null): array
    {
        return $request->validate([
            'lc_no' => ['nullable', 'string', 'max:60'],
            'kind' => ['required', Rule::in(LetterOfCredit::KINDS)],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'min:0'],
            'tolerance_pct' => ['numeric', 'min:0', 'max:100'],
            'margin_pct' => ['numeric', 'min:0', 'max:100'],
            'tenor_days' => ['integer', 'min:0', 'max:365'],
            'charges_amount' => ['numeric', 'min:0'],
            'applied_on' => ['nullable', 'date'],
            'issued_on' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            // The bank will not accept a shipment after expiry, so the form does not either.
            'last_shipment_date' => ['nullable', 'date', 'before_or_equal:expiry_date'],
            'incoterm' => ['nullable', 'string', 'max:20'],
            'port_of_loading' => ['nullable', 'string', 'max:80'],
            'port_of_discharge' => ['nullable', 'string', 'max:80'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'suppliers' => DB::table('suppliers')->where('is_active', true)->orderBy('name')
                ->get(['id', 'code', 'name']),
            'bankAccounts' => DB::table('bank_accounts')->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'bank_name', 'currency_id']),
            'currencies' => DB::table('currencies')->orderBy('code')->get(['id', 'code', 'name']),
            'kinds' => LetterOfCredit::KINDS,
        ];
    }
}
