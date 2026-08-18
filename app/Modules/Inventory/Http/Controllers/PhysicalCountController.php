<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Modules\Inventory\Services\NegativeStockException;
use App\Modules\Inventory\States\PhysicalCountStateMachine;
use App\Support\Audit\AuditLog;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ListsResources;
use App\Support\Settings\Organisation;
use App\Support\States\TransitionDenied;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * IN-6 — physical counts. Open and counting write no stock; posting is the approval effect
 * and the only step that calls StockPostingService.
 */
class PhysicalCountController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly PhysicalCountStateMachine $states,
        private readonly Organisation $organisation,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $query = PhysicalCount::query()->with(['warehouse:id,code,name', 'creator:id,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: ['status' => 'status', 'warehouse' => 'warehouse_id'],
            sortable: ['number', 'counted_on', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Inventory/Counts/Index', [
            'counts' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (PhysicalCount $row): array => [
                    ...$row->only(['id', 'number', 'counted_on', 'status']),
                    'warehouse' => $row->warehouse?->name,
                    'creator' => $row->creator?->name,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'warehouse']),
            'warehouses' => DB::table('warehouses')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Counts/Form', [
            'count' => null,
            'warehouses' => $this->warehouses(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'counted_on' => ['nullable', 'date'],
        ]);

        $warehouse = DB::table('warehouses')->where('id', $data['warehouse_id'])->first();

        if ($warehouse === null || ! (bool) $warehouse->is_active) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'The warehouse is not active.',
            ]);
        }

        $count = DB::transaction(function () use ($data, $request): PhysicalCount {
            DB::table('warehouses')->where('id', $data['warehouse_id'])->lockForUpdate()->first();

            if ($this->hasOverlappingCount((int) $data['warehouse_id'])) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'This warehouse already has an open physical count.',
                ]);
            }

            $count = new PhysicalCount;
            $count->forceFill([
                'warehouse_id' => $data['warehouse_id'],
                'counted_on' => $data['counted_on'] ?? now()->toDateString(),
                'status' => PhysicalCount::OPEN,
                'created_by' => $request->user()?->id,
            ])->save();

            return $count;
        });

        return redirect()
            ->route('physical-counts.show', $count)
            ->with('success', 'Physical count opened. Start counting to freeze lots and print the blind sheet.');
    }

    public function show(PhysicalCount $count): Response
    {
        $count->load(['warehouse:id,code,name', 'creator:id,name', 'lines.lot.item', 'lines.counter:id,name']);

        $blind = $count->status === PhysicalCount::COUNTING;
        $lines = $this->mapLines($count, includeSystem: ! $blind);

        return Inertia::render('Inventory/Counts/Show', [
            'count' => [
                ...$count->only(['id', 'number', 'warehouse_id', 'counted_on', 'status', 'created_by']),
                'warehouse' => $count->warehouse?->only(['id', 'code', 'name']),
                'creator' => $count->creator?->only(['id', 'name']),
            ],
            'lines' => $lines,
            'availableTransitions' => $this->states->available($count),
            'canPost' => $this->states->can($count, PhysicalCount::POSTED),
            'audit' => AuditLog::query()
                ->with('user:id,name')
                ->where('auditable_type', PhysicalCount::class)
                ->where('auditable_id', $count->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (AuditLog $row): array => [
                    'id' => $row->id,
                    'event' => $row->event,
                    'old_values' => $row->old_values,
                    'new_values' => $row->new_values,
                    'created_at' => $row->created_at,
                    'user' => $row->user?->only(['id', 'name']),
                ]),
        ]);
    }

    public function edit(PhysicalCount $count): Response|RedirectResponse
    {
        if ($count->status !== PhysicalCount::COUNTING) {
            return redirect()
                ->route('physical-counts.show', $count)
                ->with('error', 'Only a count in progress can be edited.');
        }

        $count->load(['warehouse:id,code,name', 'lines.lot.item']);

        return Inertia::render('Inventory/Counts/Form', [
            'count' => [
                ...$count->only(['id', 'number', 'warehouse_id', 'counted_on', 'status']),
                'warehouse' => $count->warehouse?->only(['id', 'code', 'name']),
                'lines' => $this->mapEditableLines($count),
            ],
            'warehouses' => $this->warehouses(),
        ]);
    }

    public function update(Request $request, PhysicalCount $count): RedirectResponse
    {
        if ($count->status !== PhysicalCount::COUNTING) {
            return back()->with('error', 'Only a count in progress can be updated.');
        }

        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.id' => ['required', 'integer', 'exists:physical_count_lines,id'],
            'lines.*.counted_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($count, $data, $request): void {
            /** @var PhysicalCount $locked */
            $locked = PhysicalCount::query()->lockForUpdate()->findOrFail($count->getKey());

            if ($locked->status !== PhysicalCount::COUNTING) {
                throw ValidationException::withMessages([
                    'lines' => 'This count is no longer in progress.',
                ]);
            }

            $lineIds = PhysicalCountLine::query()
                ->where('physical_count_id', $locked->id)
                ->pluck('id')
                ->all();

            foreach ($data['lines'] as $index => $line) {
                if (! in_array((int) $line['id'], $lineIds, true)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.id" => 'That line does not belong to this count.',
                    ]);
                }

                PhysicalCountLine::query()
                    ->where('id', $line['id'])
                    ->where('physical_count_id', $locked->id)
                    ->update([
                        'counted_qty' => $line['counted_qty'],
                        'counted_by' => $request->user()?->id,
                        'remarks' => $line['remarks'] ?? null,
                    ]);
            }
        });

        return redirect()
            ->route('physical-counts.show', $count)
            ->with('success', 'Counted quantities saved.');
    }

    public function transition(Request $request, PhysicalCount $count): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string']]);

        try {
            $this->states->transition($count, $data['to'], $data);
        } catch (TransitionDenied|NegativeStockException $e) {
            return back()->with('error', $e->getMessage());
        }

        $count->refresh();

        return back()->with('success', sprintf(
            'Physical count %s is now %s.',
            $count->number ?? '#'.$count->id,
            $data['to'],
        ));
    }

    public function print(Request $request, PhysicalCount $count): View
    {
        abort_unless($request->user()?->hasPermission('physical_count.view'), 403);

        if (! in_array($count->status, [PhysicalCount::COUNTING, PhysicalCount::RECONCILED, PhysicalCount::POSTED], true)) {
            abort(403, 'The blind count sheet is only available once counting has started.');
        }

        $count->load(['warehouse:id,code,name', 'lines.lot.item']);

        $binCodes = $this->binCodeByLot($count);

        $lines = $count->lines->map(fn (PhysicalCountLine $line): array => [
            'lot_no' => $line->lot?->lot_no,
            'item_code' => $line->lot?->item?->code,
            'bin_code' => $binCodes[$line->lot_id] ?? null,
            'remarks' => $line->remarks,
        ])->all();

        $this->audit->record($count, 'printed');

        return view('print.physical-count', [
            'organisation' => $this->organisation->forFrontend(),
            'count' => $count,
            'lines' => $lines,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, \stdClass> */
    private function warehouses(): \Illuminate\Support\Collection
    {
        return DB::table('warehouses')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    private function hasOverlappingCount(int $warehouseId, ?int $exceptId = null): bool
    {
        return PhysicalCount::query()
            ->where('warehouse_id', $warehouseId)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->whereIn('status', PhysicalCount::NON_TERMINAL)
            ->exists();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapLines(PhysicalCount $count, bool $includeSystem): array
    {
        $binCodes = $this->binCodeByLot($count);

        $lines = $count->lines->map(function (PhysicalCountLine $line) use ($includeSystem, $binCodes): array {
            $lot = $line->lot;
            $row = [
                'id' => $line->id,
                'lot_id' => $line->lot_id,
                'lot_no' => $lot?->lot_no,
                'item_code' => $lot?->item?->code,
                'bin_code' => $binCodes[$line->lot_id] ?? null,
                'counted_qty' => $line->counted_qty,
                'counted_by' => $line->counter?->name,
                'remarks' => $line->remarks,
            ];

            if ($includeSystem) {
                $unitCost = (float) ($lot->unit_cost ?? 0);
                $variance = $line->counted_qty !== null
                    ? (float) $line->counted_qty - (float) $line->system_qty
                    : null;
                $valueImpact = $variance !== null ? abs($variance) * $unitCost : null;

                $row['system_qty'] = (float) $line->system_qty;
                $row['unit_cost'] = $unitCost;
                $row['variance_qty'] = $variance;
                $row['value_impact'] = $valueImpact !== null ? round($valueImpact, 4) : null;
            }

            return $row;
        });

        if ($includeSystem) {
            $lines = $lines->sortByDesc(fn (array $row): float => (float) ($row['value_impact'] ?? 0));
        }

        return $lines->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function mapEditableLines(PhysicalCount $count): array
    {
        $binCodes = $this->binCodeByLot($count);

        return $count->lines->map(fn (PhysicalCountLine $line): array => [
            'id' => $line->id,
            'lot_id' => $line->lot_id,
            'lot_no' => $line->lot?->lot_no,
            'item_code' => $line->lot?->item?->code,
            'bin_code' => $binCodes[$line->lot_id] ?? null,
            'counted_qty' => $line->counted_qty,
            'remarks' => $line->remarks,
        ])->all();
    }

    /** @return array<int, string> */
    private function binCodesForLines(PhysicalCount $count): array
    {
        $binIds = $count->lines
            ->map(fn (PhysicalCountLine $line): ?int => $line->lot?->bin_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($binIds === []) {
            return [];
        }

        return DB::table('bins')
            ->whereIn('id', $binIds)
            ->pluck('code', 'id')
            ->all();
    }

    /** @return array<int, string> */
    private function binCodeByLot(PhysicalCount $count): array
    {
        $binCodes = $this->binCodesForLines($count);
        $byLot = [];

        foreach ($count->lines as $line) {
            $binId = $line->lot?->bin_id;

            if ($binId !== null) {
                $byLot[$line->lot_id] = $binCodes[$binId] ?? null;
            }
        }

        return $byLot;
    }
}
