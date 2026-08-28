<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\MasterData\Models\Item;
use App\Modules\Procurement\Models\Grn;
use App\Support\Calculators\InventoryValuator;
use App\Support\Http\ListsResources;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Goods receipt — where certification enters the system.
 *
 * `cert_scheme`, `cert_claim_pct` and `cert_document_no` on a GRN line copy onto the stock lot
 * (I5) and are the **only** legitimate origin of a certified claim. Nothing downstream may
 * invent one; that is Gate 2's foundation.
 */
class GrnController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly StockPostingService $posting,
        private readonly InventoryValuator $valuator,
        private readonly NumberAllocator $numbers,
    ) {}

    public function index(Request $request): Response
    {
        $query = Grn::query()->with(['supplier:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'invoice_no', 'challan_no'],
            filters: ['status' => 'status', 'supplier' => 'supplier_id'],
            sortable: ['number', 'received_on', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Procurement/Grns/Index', [
            'grns' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Grn $grn): array => [
                    ...$grn->only(['id', 'number', 'invoice_no', 'challan_no', 'received_on', 'status',
                        'freight_amount', 'duty_amount', 'clearing_amount']),
                    'supplier' => $grn->supplier?->name,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'supplier']),
        ]);
    }

    public function create(Request $request): Response
    {
        // The order's own currency travels with it: a USD order priced its lines in dollars and
        // the receiving form rendered them against the factory's currency, so the storekeeper
        // was asked to confirm a rate in a unit the order never used (BR-50).
        $orders = DB::table('purchase_orders as po')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'po.currency_id')
            ->whereIn('po.status', ['approved', 'sent', 'partially_received'])
            ->orderByDesc('po.id')
            ->get(['po.id', 'po.number', 'po.supplier_id', 'cur.code as currency', 'po.exchange_rate']);

        $requested = $request->integer('po') ?: null;

        // Only an order still open to receiving; anything else would leave the picker showing
        // an id it does not list and the supplier filter with nothing to match.
        $preselect = $orders->contains(fn ($order): bool => (int) $order->id === $requested)
            ? $requested
            : null;

        // The lines of that order, with what is still outstanding on each.
        //
        // The handoff used to carry the supplier and the order number and stop there, so the
        // storekeeper retyped every item, quantity and rate from the paperwork in front of
        // them — which is where a wrong item code or a transposed rate comes from. Resolved
        // server-side and scoped to the *same* set the picker offers, so a stale or
        // out-of-scope `?po=` yields nothing rather than the wrong order's lines.
        $poLines = $preselect === null ? collect() : DB::table('purchase_order_lines as pol')
            ->join('items as i', 'i.id', '=', 'pol.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'pol.uom_id')
            ->where('pol.po_id', $preselect)
            ->orderBy('pol.line_no')
            ->get([
                'pol.id', 'pol.line_no', 'pol.item_id', 'pol.uom_id', 'pol.qty',
                'pol.received_qty', 'pol.rate', 'pol.description',
                'i.code as item_code', 'i.name as item_name',
                'u.code as uom_code',
            ])
            ->map(function (object $line): object {
                // What is left to receive. A fully received line is offered but not ticked:
                // an over-receipt is a real thing and is the buyer's decision, not a silent one.
                $line->remaining_qty = round(max(0.0, (float) $line->qty - (float) $line->received_qty), 6);

                return $line;
            });

        return Inertia::render('Procurement/Grns/Form', [
            'suppliers' => DB::table('suppliers')->where('is_active', true)->orderBy('name')
                ->get(['id', 'code', 'name']),
            'warehouses' => DB::table('warehouses')->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'kind']),
            'items' => DB::table('items')->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'base_uom_id', 'std_rate', 'is_shade_critical', 'has_expiry', 'shelf_life_days']),
            'uoms' => DB::table('uoms')->orderBy('code')->get(['id', 'code', 'name']),
            'purchaseOrders' => $orders,
            // `?po=` carries the order the storekeeper is receiving against, so the supplier
            // and the order are already chosen when the goods are on the bench.
            'preselectPoId' => $preselect,
            // …and the lines, so they are checked rather than transcribed.
            'poLines' => $poLines->values(),
            'schemes' => ['GRS', 'FSC', 'OEKO_TEX', 'SCOPE'],
        ]);
    }

    /**
     * A GRN line may only answer a line of the purchase order it names.
     *
     * Without this the field is an id the client chooses, and pointing it at another supplier's
     * order line would credit that line's receipt and move its outstanding quantity. Ids that
     * do not belong are dropped, not refused — losing the provenance must not lose the goods
     * receipt, which has stock behind it.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function scopePoLines(?int $poId, array $lines): array
    {
        $permitted = $poId === null
            ? []
            : DB::table('purchase_order_lines')->where('po_id', $poId)->pluck('id')
                ->map(fn ($id): int => (int) $id)->all();

        return array_map(function (array $line) use ($permitted): array {
            $candidate = isset($line['po_line_id']) ? (int) $line['po_line_id'] : null;

            $line['po_line_id'] = $candidate !== null && in_array($candidate, $permitted, true)
                ? $candidate
                : null;

            return $line;
        }, $lines);
    }

    /**
     * Refresh a purchase order's received quantities, and its status, from its goods receipts.
     *
     * The order is `received` once every line has its full quantity, `partially_received` while
     * something has arrived and something has not, and otherwise left alone — an order nobody
     * has received against keeps whatever the buyer set.
     */
    private function rollUpReceipts(?int $poId): void
    {
        if ($poId === null) {
            return;
        }

        $lines = DB::table('purchase_order_lines')->where('po_id', $poId)->get(['id', 'qty']);

        if ($lines->isEmpty()) {
            return;
        }

        $received = DB::table('grn_lines as gl')
            ->join('grns as g', 'g.id', '=', 'gl.grn_id')
            ->whereIn('gl.po_line_id', $lines->pluck('id'))
            ->where('g.status', 'posted')
            ->groupBy('gl.po_line_id')
            ->selectRaw('gl.po_line_id, SUM(gl.received_qty) AS qty')
            ->pluck('qty', 'po_line_id');

        $complete = true;
        $any = false;

        foreach ($lines as $line) {
            $qty = (float) ($received[$line->id] ?? 0);

            DB::table('purchase_order_lines')->where('id', $line->id)->update(['received_qty' => $qty]);

            $qty > 0 ? $any = true : null;

            if ($qty + 0.000001 < (float) $line->qty) {
                $complete = false;
            }
        }

        if (! $any) {
            return;
        }

        DB::table('purchase_orders')->where('id', $poId)->update([
            'status' => $complete ? 'received' : 'partially_received',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'po_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'received_on' => ['required', 'date'],
            'invoice_no' => ['nullable', 'string', 'max:60'],
            'challan_no' => ['nullable', 'string', 'max:60'],
            // BR-36 — landed cost, apportioned by value before the average moves. Mandatory
            // given that yarn and ink are imported.
            'freight_amount' => ['numeric', 'min:0'],
            'duty_amount' => ['numeric', 'min:0'],
            'clearing_amount' => ['numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            // Which purchase-order line this receipt answers. The column has been on
            // `grn_lines` all along and nothing ever wrote it, so a GRN knew its order and not
            // what on that order it was receiving — and the outstanding quantity per line could
            // not be worked out at all.
            'lines.*.po_line_id' => ['nullable', 'integer', 'exists:purchase_order_lines,id'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.shade_code' => ['nullable', 'string', 'max:40'],
            'lines.*.supplier_batch_no' => ['nullable', 'string', 'max:60'],
            'lines.*.roll_length_m' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.expiry_date' => ['nullable', 'date', 'after:today'],
            'lines.*.cert_scheme' => ['nullable', Rule::in(['GRS', 'FSC', 'OEKO_TEX', 'SCOPE'])],
            'lines.*.cert_claim_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.cert_document_no' => ['nullable', 'string', 'max:80'],
        ]);

        // A line id belonging to a different order would read that order's quantities onto
        // this receipt. Ids that do not belong are dropped rather than refused: the pairing is
        // provenance the form filled in, not something the storekeeper typed.
        $data['lines'] = $this->scopePoLines($data['po_id'] ?? null, $data['lines']);

        $grn = DB::transaction(function () use ($data, $request): Grn {
            $grn = Grn::query()->create([
                'number' => $this->numbers->next('grn'),
                'supplier_id' => $data['supplier_id'],
                'po_id' => $data['po_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'received_on' => $data['received_on'],
                'invoice_no' => $data['invoice_no'] ?? null,
                'challan_no' => $data['challan_no'] ?? null,
                'freight_amount' => $data['freight_amount'],
                'duty_amount' => $data['duty_amount'],
                'clearing_amount' => $data['clearing_amount'],
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            // BR-36 — apportion by line value, not by quantity: a kilo of imported ink and a
            // kilo of local carton board do not carry the same share of the duty bill.
            $lineValues = array_map(
                fn (array $line): array => ['line_value' => (float) $line['qty'] * (float) $line['rate']],
                $data['lines'],
            );

            $landed = $this->valuator->apportionLandedCost(
                $lineValues,
                (float) $data['freight_amount'],
                (float) $data['duty_amount'],
                (float) $data['clearing_amount'],
            );

            foreach ($data['lines'] as $index => $line) {
                $qty = (float) $line['qty'];
                $landedUnitCost = (float) $line['rate'] + ($qty > 0 ? $landed[$index] / $qty : 0);

                $grnLineId = DB::table('grn_lines')->insertGetId([
                    'grn_id' => $grn->id,
                    'line_no' => $index + 1,
                    'po_line_id' => $line['po_line_id'] ?? null,
                    'item_id' => $line['item_id'],
                    'uom_id' => $line['uom_id'],
                    'received_qty' => $qty,
                    'accepted_qty' => $qty,
                    'rejected_qty' => 0,
                    'rate' => $line['rate'],
                    'landed_rate' => round($landedUnitCost, 4),
                    'shade_code' => $line['shade_code'] ?? null,
                    'supplier_batch_no' => $line['supplier_batch_no'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'cert_scheme' => $line['cert_scheme'] ?? null,
                    'cert_claim_pct' => $line['cert_claim_pct'] ?? 0,
                    'cert_document_no' => $line['cert_document_no'] ?? null,
                ]);

                $item = Item::query()->find($line['item_id']);

                // The lot inherits the claim from the GRN line (I5). This copy is the link an
                // auditor follows from a shipment back to a supplier certificate.
                $this->posting->receive(
                    [
                        'lot_no' => $this->numbers->nextLotNumber(),
                        'item_id' => $line['item_id'],
                        'kind' => 'raw_material',
                        'warehouse_id' => $data['warehouse_id'],
                        'uom_id' => $line['uom_id'],
                        'grn_line_id' => $grnLineId,
                        'supplier_batch_no' => $line['supplier_batch_no'] ?? null,
                        'shade_code' => $line['shade_code'] ?? null,
                        'roll_length_m' => $line['roll_length_m'] ?? null,
                        'received_on' => $data['received_on'],
                        // BR-39 — an item with a shelf life gets its expiry computed here
                        // rather than relying on the store keeper to work it out.
                        'expiry_date' => $line['expiry_date']
                            ?? ($item !== null && $item->has_expiry && $item->shelf_life_days !== null
                                ? now()->addDays($item->shelf_life_days)->toDateString()
                                : null),
                        'cert_scheme' => $line['cert_scheme'] ?? null,
                        'cert_claim_pct' => $line['cert_claim_pct'] ?? 0,
                        'cert_document_no' => $line['cert_document_no'] ?? null,
                        'status' => 'available',
                    ],
                    $qty,
                    round($landedUnitCost, 4),
                    $grn,
                );

                // BR-42 — the certified input side of the reconciliation, written at the only
                // point a certified claim legitimately enters the system.
                if (! empty($line['cert_scheme']) && (float) ($line['cert_claim_pct'] ?? 0) > 0) {
                    DB::table('coc_transactions')->insert([
                        'scheme' => $line['cert_scheme'],
                        'direction' => 'input',
                        'grn_line_id' => $grnLineId,
                        'item_id' => $line['item_id'],
                        'uom_id' => $line['uom_id'],
                        'qty' => round($qty * (float) $line['cert_claim_pct'] / 100, 6),
                        'claim_pct' => $line['cert_claim_pct'],
                        'document_no' => $line['cert_document_no'] ?? null,
                        'period_year' => (int) date('Y', strtotime($data['received_on'])),
                        'period_month' => (int) date('n', strtotime($data['received_on'])),
                        'created_by' => $request->user()->id,
                        'created_at' => now(),
                    ]);
                }
            }

            $grn->update(['status' => 'posted']);

            // What the order has now had against it. Recomputed from the receipts rather than
            // incremented, so it is the same answer however many times it is asked and a
            // corrected GRN cannot leave the figure drifting.
            //
            // Without this `purchase_order_lines.received_qty` never moved, so every line
            // looked fully outstanding for ever: the second receipt against an order offered
            // the whole quantity again, and "partially received" was a status nothing could
            // reach.
            $this->rollUpReceipts($data['po_id'] ?? null);

            return $grn;
        });

        return redirect()
            ->route('grns.show', $grn)
            ->with('success', "GRN {$grn->number} posted. Lots created and stock ledger written.");
    }

    public function show(Grn $grn): Response
    {
        $grn->load('supplier');

        return Inertia::render('Procurement/Grns/Show', [
            'grn' => $grn,
            // The receipt's place in the chain: without these two the page was a dead end —
            // no way up to the PO it received against, no way on to the bill it should seed.
            'purchaseOrder' => $grn->po_id
                ? DB::table('purchase_orders')->where('id', $grn->po_id)->first(['id', 'number'])
                : null,
            'lines' => DB::table('grn_lines as gl')
                ->join('items as i', 'i.id', '=', 'gl.item_id')
                ->leftJoin('uoms as u', 'u.id', '=', 'gl.uom_id')
                ->where('gl.grn_id', $grn->id)
                ->orderBy('gl.line_no')
                ->get([
                    'gl.id', 'gl.line_no', 'i.code as item_code', 'i.name as item_name', 'u.code as uom',
                    'gl.received_qty', 'gl.accepted_qty', 'gl.rejected_qty', 'gl.rate', 'gl.landed_rate',
                    'gl.shade_code', 'gl.supplier_batch_no', 'gl.cert_scheme', 'gl.cert_claim_pct',
                    'gl.cert_document_no',
                ]),
            'lots' => DB::table('stock_lots as sl')
                ->join('grn_lines as gl', 'gl.id', '=', 'sl.grn_line_id')
                ->where('gl.grn_id', $grn->id)
                ->get(['sl.id', 'sl.lot_no', 'sl.balance_qty', 'sl.unit_cost', 'sl.status', 'sl.barcode']),
        ]);
    }

    public function post(Grn $grn): RedirectResponse
    {
        if ($grn->status !== 'draft') {
            return back()->with('error', 'This GRN is already posted.');
        }

        $grn->update(['status' => 'posted']);

        return back()->with('success', "GRN {$grn->number} posted.");
    }
}
