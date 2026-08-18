<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Modules\Inventory\Services\NegativeStockException;
use App\Modules\Inventory\Services\ReservationService;
use App\Modules\Inventory\States\StockTransferStateMachine;
use App\Modules\MasterData\Models\Warehouse;
use App\Support\Audit\AuditLog;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * IN-4 — warehouse transfers. Draft and edit write no stock; dispatch and receive are
 * state-machine transitions that go through StockPostingService.
 */
class StockTransferController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly StockTransferStateMachine $states,
        private readonly ReservationService $reservations,
    ) {}

    public function index(Request $request): Response
    {
        $query = StockTransfer::query()->with([
            'fromWarehouse:id,code,name',
            'toWarehouse:id,code,name',
            'creator:id,name',
        ]);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'remarks'],
            filters: [
                'status' => 'status',
                'from_warehouse' => 'from_warehouse_id',
                'to_warehouse' => 'to_warehouse_id',
            ],
            sortable: ['number', 'transfer_date', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Inventory/Transfers/Index', [
            'transfers' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (StockTransfer $row): array => [
                    ...$row->only(['id', 'number', 'transfer_date', 'status']),
                    'from_warehouse' => $row->fromWarehouse?->name,
                    'to_warehouse' => $row->toWarehouse?->name,
                    'creator' => $row->creator?->name,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'from_warehouse', 'to_warehouse']),
            'warehouses' => $this->selectableWarehouses(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Transfers/Form', [
            'transfer' => null,
            'warehouses' => $this->selectableWarehouses(),
            'lots' => $this->candidateLots(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertTransitReady();
        $lines = $this->validatedLines((int) $data['from_warehouse_id'], $data['lines']);

        $transfer = DB::transaction(function () use ($data, $lines, $request): StockTransfer {
            $transfer = new StockTransfer;
            $transfer->forceFill([
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_warehouse_id' => $data['to_warehouse_id'],
                'transfer_date' => $data['transfer_date'],
                'remarks' => $data['remarks'] ?? null,
                'status' => StockTransfer::DRAFT,
                'created_by' => $request->user()?->id,
            ])->save();

            $this->replaceLines($transfer, $lines);

            return $transfer;
        });

        return redirect()
            ->route('stock-transfers.show', $transfer)
            ->with('success', 'Transfer drafted. Dispatch it to move stock into transit.');
    }

    public function show(StockTransfer $transfer): Response
    {
        $transfer->load([
            'fromWarehouse:id,code,name,kind,is_nettable',
            'toWarehouse:id,code,name,kind,is_nettable',
            'creator:id,name',
            'lines.lot',
        ]);

        $transit = $this->transitWarehouseOrNull();

        $lines = $transfer->lines->map(function (StockTransferLine $line) use ($transfer, $transit): array {
            $lot = $line->lot;
            $transitLot = $transit === null
                ? null
                : $this->safeChild($transfer, (int) $line->lot_id, (int) $transit->id);
            $destinationLot = $this->safeChild($transfer, (int) $line->lot_id, (int) $transfer->to_warehouse_id);

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
                'qty' => $line->qty,
                'received_qty' => $line->received_qty,
                'transit_lot_no' => $transitLot?->lot_no,
                'transit_balance_qty' => $transitLot?->balance_qty,
                'destination_lot_no' => $destinationLot?->lot_no,
                'destination_balance_qty' => $destinationLot?->balance_qty,
            ];
        })->all();

        return Inertia::render('Inventory/Transfers/Show', [
            'transfer' => [
                ...$transfer->only([
                    'id', 'number', 'from_warehouse_id', 'to_warehouse_id',
                    'transfer_date', 'status', 'remarks', 'created_by',
                ]),
                'from_warehouse' => $transfer->fromWarehouse?->only(['id', 'code', 'name']),
                'to_warehouse' => $transfer->toWarehouse?->only(['id', 'code', 'name']),
                'creator' => $transfer->creator?->only(['id', 'name']),
            ],
            'lines' => $lines,
            'availableTransitions' => $this->states->available($transfer),
            'audit' => AuditLog::query()
                ->with('user:id,name')
                ->where('auditable_type', StockTransfer::class)
                ->where('auditable_id', $transfer->id)
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

    public function edit(StockTransfer $transfer): Response|RedirectResponse
    {
        if ($transfer->status !== StockTransfer::DRAFT) {
            return redirect()
                ->route('stock-transfers.show', $transfer)
                ->with('error', 'Only a draft transfer can be edited.');
        }

        $transfer->load('lines.lot');

        return Inertia::render('Inventory/Transfers/Form', [
            'transfer' => [
                ...$transfer->only(['id', 'number', 'from_warehouse_id', 'to_warehouse_id', 'remarks', 'status']),
                'transfer_date' => $transfer->transfer_date->toDateString(),
                'lines' => $transfer->lines->map(fn (StockTransferLine $line): array => [
                    'lot_id' => $line->lot_id,
                    'lot_no' => $line->lot?->lot_no,
                    'qty' => $line->qty,
                    'unit_cost' => $line->lot?->unit_cost,
                    'balance_qty' => $line->lot?->balance_qty,
                    'status' => $line->lot?->status,
                ])->all(),
            ],
            'warehouses' => $this->selectableWarehouses(),
            'lots' => $this->candidateLots((int) $transfer->from_warehouse_id),
        ]);
    }

    public function update(Request $request, StockTransfer $transfer): RedirectResponse
    {
        if ($transfer->status !== StockTransfer::DRAFT) {
            return back()->with('error', 'A dispatched transfer cannot be edited.');
        }

        $data = $this->validated($request);
        $this->assertTransitReady();
        $lines = $this->validatedLines((int) $data['from_warehouse_id'], $data['lines']);

        DB::transaction(function () use ($transfer, $data, $lines): void {
            $transfer->forceFill([
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_warehouse_id' => $data['to_warehouse_id'],
                'transfer_date' => $data['transfer_date'],
                'remarks' => $data['remarks'] ?? null,
            ])->save();

            $this->replaceLines($transfer, $lines);
        });

        return redirect()
            ->route('stock-transfers.show', $transfer)
            ->with('success', 'Draft transfer updated.');
    }

    public function transition(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'received_qty' => ['nullable', 'numeric'],
            'lines' => ['nullable', 'array'],
            'lines.*.lot_id' => ['nullable', 'integer'],
            'lines.*.received_qty' => ['nullable', 'numeric'],
        ]);

        try {
            $this->states->transition($transfer, $data['to'], $data);
        } catch (TransitionDenied|NegativeStockException $e) {
            return back()->with('error', $e->getMessage());
        }

        $transfer->refresh();

        return back()->with('success', sprintf(
            'Transfer %s is now %s.',
            $transfer->number ?? '#'.$transfer->id,
            $data['to'],
        ));
    }

    /**
     * @return array{
     *     from_warehouse_id: int,
     *     to_warehouse_id: int,
     *     transfer_date: string,
     *     remarks: string|null,
     *     lines: list<array<string, mixed>>
     * }
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:to_warehouse_id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'transfer_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.lot_id' => ['required', 'integer', 'exists:stock_lots,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $this->assertSelectableWarehouse((int) $data['from_warehouse_id'], 'from_warehouse_id');
        $this->assertSelectableWarehouse((int) $data['to_warehouse_id'], 'to_warehouse_id');

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{lot_id: int, qty: float}>
     */
    private function validatedLines(int $fromWarehouseId, array $lines): array
    {
        $validated = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            $qty = (float) $line['qty'];

            if ($qty <= 0.000001) {
                throw ValidationException::withMessages([
                    "lines.{$index}.qty" => 'A transfer quantity must be greater than zero.',
                ]);
            }

            $lotId = (int) $line['lot_id'];

            if (isset($seen[$lotId])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.lot_id" => 'A source lot may appear only once on a transfer.',
                ]);
            }

            $seen[$lotId] = true;

            /** @var StockLot $lot */
            $lot = StockLot::query()->findOrFail($lotId);

            if ((int) $lot->warehouse_id !== $fromWarehouseId) {
                throw ValidationException::withMessages([
                    "lines.{$index}.lot_id" => "Lot {$lot->lot_no} is not in the source warehouse.",
                ]);
            }

            if ((string) $lot->status !== 'available') {
                throw ValidationException::withMessages([
                    "lines.{$index}.lot_id" => "Lot {$lot->lot_no} is {$lot->status} and cannot be transferred.",
                ]);
            }

            if ($qty > (float) $lot->balance_qty + 0.000001) {
                throw ValidationException::withMessages([
                    "lines.{$index}.qty" => "Lot {$lot->lot_no} only holds {$lot->balance_qty}.",
                ]);
            }

            $validated[] = [
                'lot_id' => (int) $lot->id,
                'qty' => $qty,
            ];
        }

        return $validated;
    }

    /**
     * @param  list<array{lot_id: int, qty: float}>  $lines
     */
    private function replaceLines(StockTransfer $transfer, array $lines): void
    {
        StockTransferLine::query()->where('stock_transfer_id', $transfer->getKey())->delete();

        foreach ($lines as $index => $line) {
            StockTransferLine::query()->create([
                'stock_transfer_id' => $transfer->id,
                'line_no' => $index + 1,
                'lot_id' => $line['lot_id'],
                'qty' => $line['qty'],
            ]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, Warehouse> */
    private function selectableWarehouses(): \Illuminate\Support\Collection
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->where('kind', '!=', 'transit')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'kind']);
    }

    /** @return list<array<string, mixed>> */
    private function candidateLots(?int $warehouseId = null): array
    {
        return StockLot::query()
            ->with(['item:id,code,name', 'product:id,code,name'])
            ->where('status', 'available')
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->where('balance_qty', '>', 0)
            ->orderBy('lot_no')
            ->limit(400)
            ->get()
            ->map(function (StockLot $lot): array {
                $claimed = $this->reservations->claimedByOthers((int) $lot->id);

                return [
                    'id' => $lot->id,
                    'lot_no' => $lot->lot_no,
                    'warehouse_id' => $lot->warehouse_id,
                    'status' => $lot->status,
                    'balance_qty' => $lot->balance_qty,
                    'free_qty' => max(0, (float) $lot->balance_qty - $claimed),
                    'unit_cost' => $lot->unit_cost,
                    'item' => $lot->item?->only(['id', 'code', 'name']),
                    'product' => $lot->product?->only(['id', 'code', 'name']),
                ];
            })
            ->all();
    }

    private function assertSelectableWarehouse(int $warehouseId, string $field): void
    {
        $warehouse = Warehouse::query()->find($warehouseId);

        if ($warehouse === null || ! $warehouse->is_active) {
            throw ValidationException::withMessages([
                $field => 'The warehouse must exist and be active.',
            ]);
        }

        if ($warehouse->kind === 'transit') {
            throw ValidationException::withMessages([
                $field => 'The transit warehouse cannot be selected as the source or destination.',
            ]);
        }
    }

    private function assertTransitReady(): void
    {
        try {
            $this->states->transitWarehouse();
        } catch (TransitionDenied $e) {
            throw ValidationException::withMessages([
                'from_warehouse_id' => $e->getMessage(),
            ]);
        }
    }

    private function transitWarehouseOrNull(): ?Warehouse
    {
        try {
            return $this->states->transitWarehouse();
        } catch (TransitionDenied) {
            return null;
        }
    }

    private function safeChild(StockTransfer $transfer, int $sourceLotId, int $warehouseId): ?StockLot
    {
        try {
            return $this->states->childLot($transfer, $sourceLotId, $warehouseId);
        } catch (TransitionDenied) {
            return null;
        }
    }
}
