<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\NegativeStockException;
use App\Modules\Inventory\Services\ReservationService;
use App\Modules\Inventory\Services\StockAvailability;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\MaterialIssue;
use App\Modules\MasterData\Models\Item;
use App\Support\Audit\DocumentTrail;
use App\Support\Calculators\InventoryValuator;
use App\Support\Http\ContextualId;
use App\Support\Http\ListsResources;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    use ContextualId;
    use ListsResources;

    /** @var list<string> */
    private const RETURNABLE_JOB_STATUSES = [
        JobCard::RELEASED,
        JobCard::IN_PRODUCTION,
        JobCard::QC_PENDING,
        JobCard::COMPLETED,
    ];

    public function __construct(
        private readonly StockPostingService $posting,
        private readonly StockAvailability $availability,
        private readonly InventoryValuator $valuator,
        private readonly NumberAllocator $numbers,
        private readonly ReservationService $reservations,
        private readonly DocumentTrail $trail,
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
            'issues' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (MaterialIssue $issue): array => [
                    ...$issue->only(['id', 'number', 'issued_on', 'issue_type', 'status', 'job_card_id']),
                    // The row said `job_card_id: 14`. Which job that is was a second lookup.
                    'job_card_number' => DB::table('job_cards')->where('id', $issue->job_card_id)->value('number'),
                    'warehouse' => DB::table('warehouses')->where('id', $issue->warehouse_id)->value('code'),
                    'line_count' => DB::table('material_issue_lines')
                        ->where('material_issue_id', $issue->id)->count(),
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'job_card']),
        ]);
    }

    /**
     * One issue, with the lots it actually moved.
     *
     * A material issue is a posted, ledger-bearing document — it is what took the yarn out of
     * the store and priced the job — and it had no detail view at all: index and create, and
     * nothing in between. The chain job card → issue → lot could be walked in the database and
     * nowhere on screen, so "which lot went into this order" was unanswerable to the person
     * whose job it is to answer it (BR-3, BR-37 shade traceability).
     *
     * Read-only by design. A posted stock movement is reversed by a return, never edited.
     */
    public function show(Request $request, MaterialIssue $materialIssue): Response
    {
        $card = DB::table('job_cards as jc')
            ->leftJoin('products as p', 'p.id', '=', 'jc.product_id')
            ->where('jc.id', $materialIssue->job_card_id)
            ->first(['jc.id', 'jc.number', 'jc.status', 'jc.planned_qty', 'jc.colourway',
                'p.code as product_code', 'p.name as product_name']);

        $lines = DB::table('material_issue_lines as mil')
            ->leftJoin('items as i', 'i.id', '=', 'mil.item_id')
            ->leftJoin('stock_lots as sl', 'sl.id', '=', 'mil.lot_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'mil.uom_id')
            ->where('mil.material_issue_id', $materialIssue->id)
            ->orderBy('mil.line_no')
            ->get([
                'mil.id', 'mil.line_no', 'mil.qty', 'mil.unit_cost', 'mil.fifo_override_reason',
                'i.code as item_code', 'i.name as item_name',
                'sl.lot_no', 'sl.id as lot_id', 'sl.shade_code',
                'u.code as uom',
            ]);

        return Inertia::render('Inventory/Issues/Show', [
            'issue' => [
                ...$materialIssue->only(['id', 'number', 'issued_on', 'issue_type', 'status', 'remarks', 'created_at']),
                'warehouse' => DB::table('warehouses')->where('id', $materialIssue->warehouse_id)
                    ->first(['id', 'code', 'name']),
                'issued_by' => DB::table('users')->where('id', $materialIssue->issued_by)->value('name'),
                'received_by' => DB::table('users')->where('id', $materialIssue->received_by)->value('name'),
                // BR-47 — stock is valued in the factory's own currency, so the total says so.
                'total_value' => (float) $lines->sum(fn ($line): float => (float) $line->qty * (float) $line->unit_cost),
            ],
            'jobCard' => $card,
            'lines' => $lines,
            'trail' => $this->trail->for($materialIssue, $request->user()),
        ]);
    }

    /** `?job_card=` carries the card the store was sent from; the picker still works. */
    public function create(Request $request): Response
    {
        $jobCards = DB::table('job_cards')
            ->whereIn('status', self::RETURNABLE_JOB_STATUSES)
            ->orderBy('number')
            ->get(['id', 'number', 'status', 'product_id', 'planned_qty', 'bom_id']);

        $requested = $this->contextualId($request, 'job_card', ['job_card.view_any', 'job_card.view']);

        return Inertia::render('Inventory/Issues/Form', [
            'jobCards' => $jobCards,
            // Only a card material may actually move against; anything else would tick a row
            // the form's own status filter then hides.
            'preselectJobCardId' => $jobCards->contains(fn ($card): bool => (int) $card->id === $requested)
                ? $requested
                : null,
            // `kind` travels with the row so the picker can say "Raw material" beside "RM"
            // rather than leaving the store keeper to know the codes. The header warehouse is
            // what filters the candidate lots (`suggest`), so choosing the wrong one returns
            // an empty pick list rather than a wrong movement — the ledger follows the lot.
            'warehouses' => DB::table('warehouses')->where('is_active', true)->where('is_nettable', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'kind']),
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

        // Query-string values arrive as strings; validation does not cast them, and both
        // arguments below are typed.
        $candidates = $this->availability->candidateLots(
            (int) $data['item_id'],
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            isset($data['required_claim_pct']) ? (float) $data['required_claim_pct'] : null,
        );

        $picks = $this->valuator->suggestLots(
            $candidates,
            (float) $data['qty'],
            isShadeCritical: (bool) $item->is_shade_critical,
            preferredShade: $data['preferred_shade'] ?? null,
            requiredClaimPct: isset($data['required_claim_pct']) ? (float) $data['required_claim_pct'] : null,
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

    /**
     * IN-3 — lots previously issued to this job, with remaining returnable quantity.
     */
    public function returnable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'job_card_id' => ['required', 'integer', 'exists:job_cards,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);

        $job = JobCard::query()->findOrFail($data['job_card_id']);

        if (! in_array($job->status, self::RETURNABLE_JOB_STATUSES, true)) {
            throw ValidationException::withMessages([
                'job_card_id' => 'Unused material can only be returned against a released, in-production, QC-pending or completed job.',
            ]);
        }

        $rows = DB::table('material_issue_lines as mil')
            ->join('material_issues as mi', 'mi.id', '=', 'mil.material_issue_id')
            ->join('stock_lots as sl', 'sl.id', '=', 'mil.lot_id')
            ->join('items as i', 'i.id', '=', 'mil.item_id')
            ->where('mi.job_card_id', $job->id)
            ->where('mi.status', MaterialIssue::POSTED)
            ->when(
                isset($data['warehouse_id']),
                fn ($query) => $query->where('sl.warehouse_id', $data['warehouse_id']),
            )
            ->groupBy('mil.lot_id', 'sl.lot_no', 'sl.warehouse_id', 'sl.unit_cost', 'sl.status', 'mil.item_id', 'i.code', 'mil.uom_id')
            ->selectRaw("
                mil.lot_id,
                sl.lot_no,
                sl.warehouse_id,
                sl.unit_cost,
                sl.status,
                mil.item_id,
                i.code as item_code,
                mil.uom_id,
                SUM(CASE WHEN mi.issue_type = 'issue' THEN mil.qty ELSE 0 END) as issued_qty,
                SUM(CASE WHEN mi.issue_type = 'return' THEN mil.qty ELSE 0 END) as returned_qty
            ")
            ->get()
            ->map(function ($row): array {
                $issued = (float) $row->issued_qty;
                $returned = (float) $row->returned_qty;
                $returnable = round($issued - $returned, 6);

                return [
                    'lot_id' => (int) $row->lot_id,
                    'lot_no' => $row->lot_no,
                    'warehouse_id' => (int) $row->warehouse_id,
                    'item_id' => (int) $row->item_id,
                    'item_code' => $row->item_code,
                    'uom_id' => (int) $row->uom_id,
                    'unit_cost' => (float) $row->unit_cost,
                    'status' => $row->status,
                    'issued_qty' => $issued,
                    'returned_qty' => $returned,
                    'returnable_qty' => $returnable,
                ];
            })
            ->filter(fn (array $row): bool => $row['returnable_qty'] > 0.000001)
            ->values();

        return response()->json(['lots' => $rows]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'job_card_id' => ['required', 'integer', 'exists:job_cards,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'issue_type' => ['nullable', 'string', Rule::in([MaterialIssue::TYPE_ISSUE, MaterialIssue::TYPE_RETURN])],
            'remarks' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.lot_id' => ['required', 'integer', 'exists:stock_lots,id'],
            'lines.*.uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.fifo_override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $data['issue_type'] = $data['issue_type'] ?? MaterialIssue::TYPE_ISSUE;

        try {
            $issue = DB::transaction(function () use ($data, $request): MaterialIssue {
                if ($data['issue_type'] === MaterialIssue::TYPE_RETURN) {
                    return $this->storeReturn($data, $request);
                }

                return $this->storeIssue($data, $request);
            });
        } catch (NegativeStockException $e) {
            return back()->with('error', $e->getMessage());
        }

        $label = $data['issue_type'] === MaterialIssue::TYPE_RETURN ? 'Return' : 'Issue';

        return redirect()
            ->route('material-issues.index')
            ->with('success', "{$label} {$issue->number} posted.");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeIssue(array $data, Request $request): MaterialIssue
    {
        $issue = MaterialIssue::query()->create([
            'number' => $this->numbers->next('material_issue'),
            'job_card_id' => $data['job_card_id'],
            'warehouse_id' => $data['warehouse_id'],
            'issued_on' => now()->toDateString(),
            'issue_type' => MaterialIssue::TYPE_ISSUE,
            'status' => MaterialIssue::POSTED,
            'issued_by' => $request->user()->id,
            'remarks' => $data['remarks'] ?? null,
        ]);

        foreach ($data['lines'] as $index => $line) {
            /** @var StockLot $lot */
            $lot = StockLot::query()->lockForUpdate()->findOrFail($line['lot_id']);

            if ((string) $lot->status !== 'available') {
                throw ValidationException::withMessages([
                    "lines.{$index}.lot_id" => sprintf(
                        'Lot %s cannot be issued while its status is %s.',
                        $lot->lot_no,
                        $lot->status,
                    ),
                ]);
            }

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

            // BR-42 — the consumption side of the chain of custody. Receipts alone cannot
            // reconcile: 180 kg received says nothing about the 96 kg that entered a job.
            // `conversion` is the schema's own name for this leg (`coc_direction_chk`).
            if ($lot->cert_scheme !== null && (float) $lot->cert_claim_pct > 0) {
                DB::table('coc_transactions')->insert([
                    'scheme' => $lot->cert_scheme,
                    'direction' => 'conversion',
                    'lot_id' => $lot->id,
                    'job_card_id' => (int) $data['job_card_id'],
                    'item_id' => $line['item_id'],
                    'uom_id' => $line['uom_id'],
                    'qty' => round((float) $line['qty'] * (float) $lot->cert_claim_pct / 100, 6),
                    'claim_pct' => $lot->cert_claim_pct,
                    'period_year' => (int) now()->format('Y'),
                    'period_month' => (int) now()->format('n'),
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                ]);
            }
        }

        return $issue;
    }

    /**
     * IN-3 — unused material back onto the same lot. Returns add stock; they do not
     * consume reservations.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeReturn(array $data, Request $request): MaterialIssue
    {
        // Lock order: job, then lots by ascending id. Returnable qty is recalculated after
        // those locks. RefreshDatabase does not prove true parallel race safety.
        /** @var JobCard $job */
        $job = JobCard::query()->lockForUpdate()->findOrFail($data['job_card_id']);

        if (! in_array($job->status, self::RETURNABLE_JOB_STATUSES, true)) {
            throw ValidationException::withMessages([
                'job_card_id' => 'Unused material can only be returned against a released, in-production, QC-pending or completed job.',
            ]);
        }

        $lines = $data['lines'];

        if (! is_array($lines) || $lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'At least one line is required.',
            ]);
        }

        $lotIds = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lotIds[] = (int) ($line['lot_id'] ?? 0);
        }

        $lotIds = array_values(array_unique($lotIds));
        sort($lotIds, SORT_NUMERIC);

        $lots = collect();

        foreach ($lotIds as $lotId) {
            /** @var StockLot $lot */
            $lot = StockLot::query()->whereKey($lotId)->lockForUpdate()->firstOrFail();
            $lots->put((int) $lot->id, $lot);
        }

        $running = [];

        foreach ($lotIds as $lotId) {
            $running[$lotId] = $this->returnableQty((int) $data['job_card_id'], $lotId);
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.lot_id" => 'Each return line needs a lot.',
                ]);
            }

            $lotId = (int) $line['lot_id'];
            $qty = (float) $line['qty'];
            $lot = $lots->get($lotId);

            if (! $lot instanceof StockLot) {
                throw ValidationException::withMessages([
                    "lines.{$index}.lot_id" => 'That lot is not available for return.',
                ]);
            }

            if ((int) $lot->warehouse_id !== (int) $data['warehouse_id']) {
                throw ValidationException::withMessages([
                    "lines.{$index}.lot_id" => "Lot {$lot->lot_no} is not in the selected warehouse.",
                ]);
            }

            if ((int) $line['item_id'] !== (int) $lot->item_id) {
                throw ValidationException::withMessages([
                    "lines.{$index}.item_id" => "Lot {$lot->lot_no} is not that item.",
                ]);
            }

            if ($running[$lotId] <= 0.000001) {
                throw ValidationException::withMessages([
                    "lines.{$index}.lot_id" => "Lot {$lot->lot_no} was not issued to this job, or has already been returned.",
                ]);
            }

            if ($qty > $running[$lotId] + 0.000001) {
                throw ValidationException::withMessages([
                    "lines.{$index}.qty" => sprintf(
                        'Only %s of lot %s can still be returned to this job.',
                        rtrim(rtrim(number_format($running[$lotId], 6, '.', ''), '0'), '.'),
                        $lot->lot_no,
                    ),
                ]);
            }

            $running[$lotId] -= $qty;
        }

        $issue = MaterialIssue::query()->create([
            'number' => $this->numbers->next('material_issue'),
            'job_card_id' => $data['job_card_id'],
            'warehouse_id' => $data['warehouse_id'],
            'issued_on' => now()->toDateString(),
            'issue_type' => MaterialIssue::TYPE_RETURN,
            'status' => MaterialIssue::POSTED,
            'issued_by' => $request->user()->id,
            'remarks' => $data['remarks'] ?? null,
        ]);

        foreach ($lines as $index => $line) {
            $lot = $lots->get((int) $line['lot_id']);

            if (! $lot instanceof StockLot) {
                throw ValidationException::withMessages([
                    "lines.{$index}.lot_id" => 'That lot is not available for return.',
                ]);
            }

            $qty = abs((float) $line['qty']);
            $wasConsumed = (string) $lot->status === 'consumed';

            DB::table('material_issue_lines')->insert([
                'material_issue_id' => $issue->id,
                'line_no' => $index + 1,
                'item_id' => $line['item_id'],
                'lot_id' => $lot->id,
                'uom_id' => $line['uom_id'],
                'qty' => $qty,
                'unit_cost' => $lot->unit_cost,
                'fifo_override_reason' => $line['fifo_override_reason'] ?? null,
            ]);

            $this->posting->post(
                $lot,
                'return_from_job',
                $qty,
                $issue,
                remarks: $line['fifo_override_reason'] ?? null,
            );

            $lot->refresh();

            if ($wasConsumed && (float) $lot->balance_qty > 0.000001 && (string) $lot->status === 'consumed') {
                $lot->forceFill(['status' => 'available'])->save();
            }
        }

        return $issue;
    }

    private function returnableQty(int $jobCardId, int $lotId): float
    {
        $issued = $this->postedQty($jobCardId, $lotId, MaterialIssue::TYPE_ISSUE);
        $returned = $this->postedQty($jobCardId, $lotId, MaterialIssue::TYPE_RETURN);

        return round($issued - $returned, 6);
    }

    private function postedQty(int $jobCardId, int $lotId, string $type): float
    {
        return (float) DB::table('material_issue_lines as mil')
            ->join('material_issues as mi', 'mi.id', '=', 'mil.material_issue_id')
            ->where('mi.job_card_id', $jobCardId)
            ->where('mi.status', MaterialIssue::POSTED)
            ->where('mi.issue_type', $type)
            ->where('mil.lot_id', $lotId)
            ->sum('mil.qty');
    }
}
