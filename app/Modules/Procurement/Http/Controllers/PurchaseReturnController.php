<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\PurchaseReturn;
use App\Modules\Procurement\Models\PurchaseReturnLine;
use App\Modules\Procurement\States\PurchaseReturnStateMachine;
use App\Support\Audit\DocumentTrail;
use App\Support\Http\ContextualId;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use App\Support\Text\Plain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PR-7 — goods sent back to a supplier. Raised against one posted receipt, from the lots that
 * receipt created, for no more than each lot still holds; posting is the stock movement, and
 * the debit note Accounts raise against the supplier is referenced on it.
 */
class PurchaseReturnController extends Controller
{
    use ContextualId;
    use ListsResources;

    public function __construct(
        private readonly PurchaseReturnStateMachine $states,
        private readonly DocumentTrail $trail,
    ) {}

    public function index(Request $request): Response
    {
        $query = PurchaseReturn::query()
            ->with(['supplier:id,code,name', 'grn:id,number'])
            ->withCount('lines')
            ->addSelect(['value' => PurchaseReturnLine::query()->selectRaw('COALESCE(SUM(qty * rate), 0)')
                ->whereColumn('purchase_return_id', 'purchase_returns.id')]);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'reason', 'debit_note_no'],
            filters: ['status' => 'status', 'supplier' => 'supplier_id'],
            sortable: ['number', 'returned_on', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Procurement/PurchaseReturns/Index', [
            'returns' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (PurchaseReturn $return): array => [
                    ...$return->only(['id', 'number', 'returned_on', 'reason', 'status', 'debit_note_no', 'lines_count']),
                    'supplier' => $return->supplier?->name,
                    'grn' => $return->grn?->only(['id', 'number']),
                    'value' => round((float) $return->getAttribute('value'), 2),
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'supplier']),
            'suppliers' => DB::table('suppliers')->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $grnId = $this->contextualId($request, 'grn', ['grn.view_any', 'grn.view']);

        return Inertia::render('Procurement/PurchaseReturns/Form', [
            ...$this->formOptions(),
            'grn' => null,
            'lots' => [],
            'preselectGrnId' => $grnId,
        ]);
    }

    /** The lots one receipt created and what each still holds, for the form to draw against. */
    public function lots(Grn $grn): Response
    {
        return Inertia::render('Procurement/PurchaseReturns/Form', [
            ...$this->formOptions(),
            'grn' => [
                ...$grn->only(['id', 'number', 'status', 'supplier_id', 'received_on']),
                'supplier' => DB::table('suppliers')->where('id', $grn->supplier_id)->value('name'),
            ],
            'lots' => $this->returnableLots($grn),
            'preselectGrnId' => $grn->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            // Only receipts with something still in stock: a receipt whose lots are all
            // consumed has nothing to send back, and offering it would offer a refusal.
            'grns' => DB::table('grns as g')
                ->join('suppliers as s', 's.id', '=', 'g.supplier_id')
                ->where('g.status', 'posted')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('stock_lots as sl')
                    ->join('grn_lines as gl', 'gl.id', '=', 'sl.grn_line_id')
                    ->whereColumn('gl.grn_id', 'g.id')
                    ->where('sl.balance_qty', '>', 0))
                ->orderByDesc('g.id')
                ->limit(200)
                ->get(['g.id', 'g.number', 'g.received_on', 'g.supplier_id', 's.name as supplier_name']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function returnableLots(Grn $grn): array
    {
        return DB::table('stock_lots as sl')
            ->join('grn_lines as gl', 'gl.id', '=', 'sl.grn_line_id')
            ->join('items as i', 'i.id', '=', 'sl.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'sl.uom_id')
            ->where('gl.grn_id', $grn->id)
            ->orderBy('gl.line_no')->orderBy('sl.id')
            ->get([
                'sl.id', 'sl.lot_no', 'sl.item_id', 'sl.balance_qty', 'sl.received_qty', 'sl.unit_cost', 'sl.status',
                'gl.line_no as grn_line_no', 'gl.rate', 'i.code as item_code', 'i.name as item_name', 'u.code as uom',
            ])
            ->map(fn (object $row): array => [
                ...(array) $row,
                'returned_qty' => (float) DB::table('purchase_return_lines as prl')
                    ->join('purchase_returns as pr', 'pr.id', '=', 'prl.purchase_return_id')
                    ->where('prl.lot_id', $row->id)->where('pr.status', PurchaseReturn::POSTED)->sum('prl.qty'),
            ])
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'grn_id' => ['required', 'integer', 'exists:grns,id'],
            'returned_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'debit_note_no' => ['nullable', 'string', 'max:60'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.lot_id' => ['required', 'integer', 'exists:stock_lots,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ], [
            'lines.required' => 'Enter the quantity going back on at least one lot.',
            'lines.min' => 'Enter the quantity going back on at least one lot.',
        ]);

        /** @var Grn $grn */
        $grn = Grn::query()->findOrFail($data['grn_id']);

        if ($grn->status !== 'posted') {
            throw ValidationException::withMessages(['grn_id' => "Goods receipt {$grn->number} is not posted, so nothing from it is in stock to send back."]);
        }

        $lots = collect($this->returnableLots($grn))->keyBy('id');

        $return = DB::transaction(function () use ($data, $grn, $lots, $request): PurchaseReturn {
            $return = PurchaseReturn::query()->create([
                'grn_id' => $grn->id,
                'supplier_id' => $grn->supplier_id,
                'returned_on' => $data['returned_on'],
                'reason' => $data['reason'],
                'debit_note_no' => $data['debit_note_no'] ?? null,
                'status' => PurchaseReturn::DRAFT,
                'created_by' => $request->user()->id,
            ]);

            foreach (array_values($data['lines']) as $index => $line) {
                $lot = $lots->get((int) $line['lot_id']);

                if ($lot === null) {
                    throw ValidationException::withMessages(["lines.{$index}.lot_id" => 'That lot did not come in on this receipt.']);
                }

                // Advisory at draft: the binding check runs under the lot's lock at posting.
                if ((float) $line['qty'] > (float) $lot['balance_qty'] + 0.000001) {
                    throw ValidationException::withMessages(["lines.{$index}.qty" => "Lot {$lot['lot_no']} holds only {$lot['balance_qty']}."]);
                }

                PurchaseReturnLine::query()->create([
                    'purchase_return_id' => $return->id,
                    'line_no' => $index + 1,
                    'item_id' => $lot['item_id'],
                    'lot_id' => $lot['id'],
                    'qty' => $line['qty'],
                    // Valued at the receipt line's rate: what the supplier charged, which is
                    // what the debit note claims back.
                    'rate' => $lot['rate'],
                ]);
            }

            return $return;
        });

        return redirect()->route('purchase-returns.show', $return)->with('success', 'Return saved as a draft. Posting it takes the goods out of stock.');
    }

    public function show(Request $request, PurchaseReturn $purchaseReturn): Response
    {
        $purchaseReturn->load(['supplier:id,code,name', 'grn:id,number,status,received_on', 'creator:id,name']);

        return Inertia::render('Procurement/PurchaseReturns/Show', [
            'purchaseReturn' => [
                ...$purchaseReturn->only(['id', 'number', 'returned_on', 'reason', 'status', 'debit_note_no']),
                'supplier' => $purchaseReturn->supplier?->only(['id', 'code', 'name']),
                'grn' => $purchaseReturn->grn?->only(['id', 'number', 'status', 'received_on']),
                'raised_by' => $purchaseReturn->creator?->name,
            ],
            'lines' => DB::table('purchase_return_lines as prl')
                ->join('items as i', 'i.id', '=', 'prl.item_id')
                ->leftJoin('stock_lots as sl', 'sl.id', '=', 'prl.lot_id')
                ->where('prl.purchase_return_id', $purchaseReturn->id)
                ->orderBy('prl.line_no')
                ->get(['prl.id', 'prl.line_no', 'prl.qty', 'prl.rate', 'i.code as item_code', 'i.name as item_name', 'sl.lot_no', 'sl.balance_qty', 'sl.status as lot_status']),
            'movements' => DB::table('stock_ledger')
                ->where('source_type', PurchaseReturn::class)
                ->where('source_id', $purchaseReturn->id)
                ->orderBy('id')
                ->get(['id', 'lot_id', 'qty', 'unit_cost', 'value', 'occurred_at']),
            'availableTransitions' => $this->states->available($purchaseReturn),
            'trail' => $this->trail->for($purchaseReturn, $request->user()),
        ]);
    }

    public function transition(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in([PurchaseReturn::POSTED, PurchaseReturn::CANCELLED])],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:to,cancelled'],
            'debit_note_no' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $this->states->transition($purchaseReturn, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Return is now '.Plain::status($data['to']).'.');
    }

    /** AC3 — the debit note usually follows the goods by days; Accounts add it when it exists. */
    public function debitNote(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        $data = $request->validate(['debit_note_no' => ['required', 'string', 'max:60']]);

        if ($purchaseReturn->status === PurchaseReturn::CANCELLED) {
            return back()->with('error', 'A cancelled return has no debit note.');
        }

        $purchaseReturn->update($data);

        return back()->with('success', 'Debit note reference recorded.');
    }
}
