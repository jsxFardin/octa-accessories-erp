<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\NegativeStockException;
use App\Modules\Inventory\Services\ReservationService;
use App\Modules\Inventory\Services\StockAvailability;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\Manufacturing\Models\MaterialIssue;
use App\Modules\MasterData\Models\Item;
use App\Support\Calculators\InventoryValuator;
use App\Support\Http\ListsResources;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Issuing material to a job card — BR-37 lot selection and BR-38 negative-stock refusal.
 *
 * The suggestion is shade-first for shade-critical items, because shade variation inside one
 * customer's order is a rejection. Breaking FIFO is allowed; breaking it without recording
 * why is not.
 */
class MaterialIssueController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly StockPostingService $posting,
        private readonly StockAvailability $availability,
        private readonly InventoryValuator $valuator,
        private readonly NumberAllocator $numbers,
        private readonly ReservationService $reservations,
    ) {}

    public function index(Request $request): Response
    {
        $query = MaterialIssue::query();

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: ['status' => 'status', 'job_card' => 'job_card_id'],
            sortable: ['number', 'issued_on', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Inventory/Issues/Index', [
            'issues' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['status', 'job_card']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Inventory/Issues/Form', [
            'jobCards' => DB::table('job_cards')
                ->whereIn('status', ['released', 'in_production'])
                ->orderBy('number')
                ->get(['id', 'number', 'product_id', 'planned_qty', 'bom_id']),
            'warehouses' => DB::table('warehouses')->where('is_active', true)->where('is_nettable', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            // The picker needs `is_shade_critical` to know whether to offer a shade at all
            // (BR-37), and the base UoM to post the line without a second lookup.
            'items' => Item::query()->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'base_uom_id', 'is_shade_critical']),
        ]);
    }

    /**
     * BR-37 — candidate lots for a required quantity, ranked. Returns whether each pick
     * breaks FIFO so the screen can demand a reason for exactly those lines.
     */
    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'preferred_shade' => ['nullable', 'string', 'max:40'],
            'required_claim_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $item = Item::query()->findOrFail($data['item_id']);

        $candidates = $this->availability->candidateLots(
            (int) $data['item_id'],
            $data['warehouse_id'] ?? null,
            $data['required_claim_pct'] ?? null,
        );

        $picks = $this->valuator->suggestLots(
            $candidates,
            (float) $data['qty'],
            isShadeCritical: (bool) $item->is_shade_critical,
            preferredShade: $data['preferred_shade'] ?? null,
            requiredClaimPct: $data['required_claim_pct'] ?? null,
        );

        $lots = StockLot::query()->whereIn('id', array_column($picks, 'id'))->get()->keyBy('id');
        $suggested = array_sum(array_column($picks, 'qty'));

        return response()->json([
            'picks' => array_map(fn (array $pick): array => [
                ...$pick,
                'lot_no' => $lots[$pick['id']]->lot_no ?? null,
                'shade_code' => $lots[$pick['id']]->shade_code ?? null,
                'balance_qty' => (float) ($lots[$pick['id']]->balance_qty ?? 0),
                'unit_cost' => (float) ($lots[$pick['id']]->unit_cost ?? 0),
                'cert_scheme' => $lots[$pick['id']]->cert_scheme ?? null,
                'cert_claim_pct' => (float) ($lots[$pick['id']]->cert_claim_pct ?? 0),
            ], $picks),
            'is_shade_critical' => (bool) $item->is_shade_critical,
            // The shortfall is stated rather than silently short-picked: BR-38 will refuse
            // the posting anyway, and finding out at post time wastes a trip to the store.
            'shortfall' => round(max(0, (float) $data['qty'] - $suggested), 6),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'job_card_id' => ['required', 'integer', 'exists:job_cards,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'issue_type' => ['nullable', 'string', 'max:20'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.lot_id' => ['required', 'integer', 'exists:stock_lots,id'],
            'lines.*.uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.fifo_override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $issue = DB::transaction(function () use ($data, $request): MaterialIssue {
                $issue = MaterialIssue::query()->create([
                    'number' => $this->numbers->next('material_issue'),
                    'job_card_id' => $data['job_card_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'issued_on' => now()->toDateString(),
                    'issue_type' => $data['issue_type'] ?? 'issue',
                    'status' => 'posted',
                    'issued_by' => $request->user()->id,
                    'remarks' => $data['remarks'] ?? null,
                ]);

                foreach ($data['lines'] as $index => $line) {
                    /** @var StockLot $lot */
                    $lot = StockLot::query()->lockForUpdate()->findOrFail($line['lot_id']);

                    // P1-2 — another job's active reservation on this lot is not ours to take.
                    // BR-38 protects the balance; this protects the claim.
                    $othersClaim = $this->reservations->claimedByOthers((int) $lot->id, (int) $data['job_card_id']);
                    $freeForThisJob = (float) $lot->balance_qty - $othersClaim;

                    if ((float) $line['qty'] > $freeForThisJob + 0.000001) {
                        throw new NegativeStockException(sprintf(
                            'P1-2: lot %s has %s on hand but %s is reserved for other jobs — only %s is available to this one.',
                            $lot->lot_no,
                            rtrim(rtrim(number_format((float) $lot->balance_qty, 6, '.', ''), '0'), '.'),
                            rtrim(rtrim(number_format($othersClaim, 6, '.', ''), '0'), '.'),
                            rtrim(rtrim(number_format(max(0, $freeForThisJob), 6, '.', ''), '0'), '.'),
                        ));
                    }

                    DB::table('material_issue_lines')->insert([
                        'material_issue_id' => $issue->id,
                        'line_no' => $index + 1,
                        'item_id' => $line['item_id'],
                        'lot_id' => $lot->id,
                        'uom_id' => $line['uom_id'],
                        'qty' => $line['qty'],
                        'unit_cost' => $lot->unit_cost,
                        'fifo_override_reason' => $line['fifo_override_reason'] ?? null,
                    ]);

                    // Manufacturing does not write the ledger; it goes through the inventory
                    // service, which holds the row lock and the BR-38 refusal.
                    $this->posting->post(
                        $lot,
                        'issue_to_job',
                        -abs((float) $line['qty']),
                        $issue,
                        remarks: $line['fifo_override_reason'] ?? null,
                    );

                    // The physical issue consumes this job's own claim, oldest rows first.
                    $this->reservations->consumeForIssue((int) $data['job_card_id'], (int) $lot->id, (float) $line['qty']);
                }

                return $issue;
            });
        } catch (NegativeStockException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('material-issues.index')
            ->with('success', "Issue {$issue->number} posted.");
    }
}
