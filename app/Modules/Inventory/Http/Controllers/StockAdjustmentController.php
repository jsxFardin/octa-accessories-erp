<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockAdjustmentLine;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\NegativeStockException;
use App\Modules\Inventory\States\StockAdjustmentStateMachine;
use App\Support\Audit\AuditLog;
use App\Support\Http\ListsResources;
use App\Support\Settings\Settings;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * IN-5 — stock adjustments. Draft and edit write no stock; posting is a state-machine
 * transition that goes through StockPostingService under the lot row lock.
 */
class StockAdjustmentController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly StockAdjustmentStateMachine $states,
        private readonly Settings $settings,
    ) {}

    public function index(Request $request): Response
    {
        $query = StockAdjustment::query()->with(['warehouse:id,code,name', 'creator:id,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'reason'],
            filters: ['status' => 'status', 'warehouse' => 'warehouse_id'],
            sortable: ['number', 'adjusted_on', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Inventory/Adjustments/Index', [
            'adjustments' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (StockAdjustment $row): array => [
                    ...$row->only(['id', 'number', 'adjusted_on', 'reason', 'status']),
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
        return Inertia::render('Inventory/Adjustments/Form', [
            'adjustment' => null,
            'warehouses' => $this->warehouses(),
            'lots' => $this->candidateLots(),
            'band' => $this->settings->decimal('adjustment_approval_band_manager', 25000),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $lines = $this->validatedLines((int) $data['warehouse_id'], $data['lines']);

        $adjustment = DB::transaction(function () use ($data, $lines, $request): StockAdjustment {
            $adjustment = new StockAdjustment;
            $adjustment->forceFill([
                'warehouse_id' => $data['warehouse_id'],
                'adjusted_on' => now()->toDateString(),
                'reason' => $data['reason'],
                'status' => StockAdjustment::DRAFT,
                'created_by' => $request->user()?->id,
            ])->save();

            $this->replaceLines($adjustment, $lines);

            return $adjustment;
        });

        return redirect()
            ->route('stock-adjustments.show', $adjustment)
            ->with('success', 'Adjustment drafted. Submit it for approval before it can move stock.');
    }

    public function show(StockAdjustment $adjustment): Response
    {
        $adjustment->load(['warehouse:id,code,name', 'creator:id,name', 'approver:id,name', 'lines.lot']);

        $lines = $adjustment->lines->map(function (StockAdjustmentLine $line): array {
            $lot = $line->lot;

            return [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'lot_id' => $line->lot_id,
                'lot_no' => $lot?->lot_no,
                'item_id' => $lot?->item_id,
                'product_id' => $lot?->product_id,
                'status' => $lot?->status,
                'balance_qty' => $lot?->balance_qty,
                'unit_cost' => $lot?->unit_cost,
                'qty_delta' => $line->qty_delta,
                'value' => round(abs((float) $line->qty_delta) * (float) ($lot->unit_cost ?? 0), 4),
                'remarks' => $line->remarks,
            ];
        })->all();

        return Inertia::render('Inventory/Adjustments/Show', [
            'adjustment' => [
                ...$adjustment->only(['id', 'number', 'warehouse_id', 'adjusted_on', 'reason', 'status', 'approved_by', 'created_by']),
                'warehouse' => $adjustment->warehouse?->only(['id', 'code', 'name']),
                'creator' => $adjustment->creator?->only(['id', 'name']),
                'approver' => $adjustment->approver?->only(['id', 'name']),
            ],
            'lines' => $lines,
            'approval' => $this->states->approvalBand($adjustment),
            'availableTransitions' => $this->states->available($adjustment),
            'canApprove' => $this->states->can($adjustment, StockAdjustment::POSTED),
            'audit' => AuditLog::query()
                ->with('user:id,name')
                ->where('auditable_type', StockAdjustment::class)
                ->where('auditable_id', $adjustment->id)
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

    public function edit(StockAdjustment $adjustment): Response|RedirectResponse
    {
        if ($adjustment->status !== StockAdjustment::DRAFT) {
            return redirect()
                ->route('stock-adjustments.show', $adjustment)
                ->with('error', 'Only a draft adjustment can be edited.');
        }

        $adjustment->load('lines.lot');

        return Inertia::render('Inventory/Adjustments/Form', [
            'adjustment' => [
                ...$adjustment->only(['id', 'number', 'warehouse_id', 'reason', 'status']),
                'lines' => $adjustment->lines->map(fn (StockAdjustmentLine $line): array => [
                    'lot_id' => $line->lot_id,
                    'lot_no' => $line->lot?->lot_no,
                    'qty_delta' => $line->qty_delta,
                    'unit_cost' => $line->lot?->unit_cost,
                    'balance_qty' => $line->lot?->balance_qty,
                    'status' => $line->lot?->status,
                    'remarks' => $line->remarks,
                ])->all(),
            ],
            'warehouses' => $this->warehouses(),
            'lots' => $this->candidateLots((int) $adjustment->warehouse_id),
            'band' => $this->settings->decimal('adjustment_approval_band_manager', 25000),
        ]);
    }

    public function update(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        if ($adjustment->status !== StockAdjustment::DRAFT) {
            return back()->with('error', 'A submitted adjustment cannot be edited. Recall it to draft first.');
        }

        $data = $this->validated($request);
        $lines = $this->validatedLines((int) $data['warehouse_id'], $data['lines']);

        DB::transaction(function () use ($adjustment, $data, $lines): void {
            $adjustment->forceFill([
                'warehouse_id' => $data['warehouse_id'],
                'reason' => $data['reason'],
            ])->save();

            $this->replaceLines($adjustment, $lines);
        });

        return redirect()
            ->route('stock-adjustments.show', $adjustment)
            ->with('success', 'Draft adjustment updated.');
    }

    public function transition(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string']]);

        try {
            $this->states->transition($adjustment, $data['to'], $data);
        } catch (TransitionDenied|NegativeStockException $e) {
            return back()->with('error', $e->getMessage());
        }

        $adjustment->refresh();

        return back()->with('success', sprintf(
            'Adjustment %s is now %s.',
            $adjustment->number ?? '#'.$adjustment->id,
            $data['to'],
        ));
    }

    /**
     * @return array{warehouse_id: int, reason: string, lines: list<array<string, mixed>>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'reason' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.lot_id' => ['required', 'integer', 'exists:stock_lots,id'],
            'lines.*.qty_delta' => ['required', 'numeric'],
            'lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{lot_id: int, qty_delta: float, remarks: string|null}>
     */
    private function validatedLines(int $warehouseId, array $lines): array
    {
        $validated = [];

        foreach ($lines as $index => $line) {
            $qty = (float) $line['qty_delta'];

            if (abs($qty) < 0.000001) {
                throw ValidationException::withMessages([
                    "lines.{$index}.qty_delta" => 'An adjustment line of zero is not an adjustment.',
                ]);
            }

            /** @var StockLot $lot */
            $lot = StockLot::query()->findOrFail($line['lot_id']);

            if ((int) $lot->warehouse_id !== $warehouseId) {
                throw ValidationException::withMessages([
                    "lines.{$index}.lot_id" => "Lot {$lot->lot_no} is not in the selected warehouse.",
                ]);
            }

            $status = (string) $lot->status;
            $allowed = $status === 'available' || ($status === 'blocked' && $qty < 0);

            if (! $allowed) {
                throw ValidationException::withMessages([
                    "lines.{$index}.lot_id" => "Lot {$lot->lot_no} is {$status} and cannot take this adjustment.",
                ]);
            }

            if ($qty < 0 && abs($qty) > (float) $lot->balance_qty + 0.000001) {
                throw ValidationException::withMessages([
                    "lines.{$index}.qty_delta" => "Lot {$lot->lot_no} only holds {$lot->balance_qty}.",
                ]);
            }

            $validated[] = [
                'lot_id' => (int) $lot->id,
                'qty_delta' => $qty,
                'remarks' => isset($line['remarks']) ? (string) $line['remarks'] : null,
            ];
        }

        return $validated;
    }

    /**
     * @param  list<array{lot_id: int, qty_delta: float, remarks: string|null}>  $lines
     */
    private function replaceLines(StockAdjustment $adjustment, array $lines): void
    {
        StockAdjustmentLine::query()->where('stock_adjustment_id', $adjustment->getKey())->delete();

        foreach ($lines as $index => $line) {
            StockAdjustmentLine::query()->create([
                'stock_adjustment_id' => $adjustment->id,
                'line_no' => $index + 1,
                'lot_id' => $line['lot_id'],
                'qty_delta' => $line['qty_delta'],
                'remarks' => $line['remarks'],
            ]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, \stdClass> */
    private function warehouses(): \Illuminate\Support\Collection
    {
        return DB::table('warehouses')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    /** @return list<array<string, mixed>> */
    private function candidateLots(?int $warehouseId = null): array
    {
        return StockLot::query()
            ->with(['item:id,code,name', 'product:id,code,name'])
            ->whereIn('status', ['available', 'blocked'])
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->where('balance_qty', '>=', 0)
            ->orderBy('lot_no')
            ->limit(400)
            ->get()
            ->map(fn (StockLot $lot): array => [
                'id' => $lot->id,
                'lot_no' => $lot->lot_no,
                'warehouse_id' => $lot->warehouse_id,
                'status' => $lot->status,
                'balance_qty' => $lot->balance_qty,
                'unit_cost' => $lot->unit_cost,
                'item' => $lot->item?->only(['id', 'code', 'name']),
                'product' => $lot->product?->only(['id', 'code', 'name']),
            ])
            ->all();
    }
}
