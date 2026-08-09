<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\MachineGroup;
use App\Modules\Product\Models\Routing;
use App\Support\Calculators\ProductType;
use App\Support\Http\ListsResources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Routings — the ordered operations a product type goes through, with the wastage and
 * make-ready figures BR-8 sums.
 *
 * `consumes_web = false` is the field that matters most on this screen: it marks the
 * operations — packing, QC — that must be excluded from the additive wastage total, or
 * wrapping a carton gets charged ribbon.
 */
class RoutingController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = Routing::query()->with('operations');

        $this->applyListing(
            $query,
            $request,
            searchable: ['code', 'name'],
            filters: ['product_type' => 'product_type'],
            sortable: ['code', 'name', 'product_type'],
            defaultSort: 'code',
        );

        return Inertia::render('Product/Routings/Index', [
            'routings' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['product_type']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Product/Routings/Form', ['routing' => null, ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $routing = DB::transaction(function () use ($data): Routing {
            $routing = Routing::query()->create(collect($data)->except('operations')->all());

            $this->syncOperations($routing, $data['operations']);

            return $routing;
        });

        return redirect()
            ->route('routings.show', $routing)
            ->with('success', "Routing {$routing->code} created.");
    }

    public function show(Routing $routing): Response
    {
        $routing->load('operations.machineGroup');

        return Inertia::render('Product/Routings/Show', [
            'routing' => $routing,
            'operations' => $routing->operations->map(fn ($operation): array => [
                ...$operation->only([
                    'id', 'sequence_no', 'code', 'name', 'std_rate_per_hour', 'setup_minutes',
                    'setup_qty', 'wastage_pct', 'manning_level', 'consumes_web', 'allow_parallel',
                    'requires_qc',
                ]),
                'machine_group' => $operation->machineGroup?->name,
            ]),
            // BR-8 — additive across the operations that consume the web, and only those.
            'totalWastagePct' => $routing->totalWastagePct(),
            'products' => DB::table('products')->where('routing_id', $routing->id)
                ->get(['id', 'code', 'name', 'status']),
        ]);
    }

    public function edit(Routing $routing): Response
    {
        $routing->load('operations');

        return Inertia::render('Product/Routings/Form', ['routing' => $routing, ...$this->options()]);
    }

    public function update(Request $request, Routing $routing): RedirectResponse
    {
        $data = $this->validated($request, $routing);

        DB::transaction(function () use ($routing, $data): void {
            $routing->update(collect($data)->except('operations')->all());
            $this->syncOperations($routing, $data['operations']);
        });

        return redirect()
            ->route('routings.show', $routing)
            ->with('success', "Routing {$routing->code} updated.");
    }

    public function destroy(Routing $routing): RedirectResponse
    {
        $inUse = DB::table('products')->where('routing_id', $routing->id)->count();

        if ($inUse > 0) {
            return back()->with('error', "{$routing->code} is used by {$inUse} product(s). Reassign them first.");
        }

        $routing->update(['is_active' => false]);

        return redirect()->route('routings.index')->with('success', "Routing {$routing->code} retired.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Routing $routing = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('routings', 'code')->ignore($routing?->id)],
            'name' => ['required', 'string', 'max:120'],
            'product_type' => ['required', Rule::in(array_column(ProductType::cases(), 'value'))],
            'max_lot_size' => ['nullable', 'numeric', 'gt:0'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'operations' => ['required', 'array', 'min:1'],
            'operations.*.code' => ['required', 'string', 'max:30'],
            'operations.*.name' => ['required', 'string', 'max:120'],
            'operations.*.machine_group_id' => ['nullable', 'integer', 'exists:machine_groups,id'],
            'operations.*.std_rate_per_hour' => ['nullable', 'numeric', 'gt:0'],
            'operations.*.setup_minutes' => ['numeric', 'min:0'],
            'operations.*.setup_qty' => ['numeric', 'min:0'],
            'operations.*.wastage_pct' => ['numeric', 'min:0'],
            'operations.*.manning_level' => ['numeric', 'min:0'],
            'operations.*.consumes_web' => ['boolean'],
            'operations.*.allow_parallel' => ['boolean'],
            'operations.*.requires_qc' => ['boolean'],
        ]);
    }

    /** @param list<array<string, mixed>> $operations */
    private function syncOperations(Routing $routing, array $operations): void
    {
        DB::table('routing_operations')->where('routing_id', $routing->id)->delete();

        foreach ($operations as $index => $operation) {
            DB::table('routing_operations')->insert([
                'routing_id' => $routing->id,
                // J2 — operations execute in sequence order, so the sequence is the row order.
                'sequence_no' => $index + 1,
                'code' => $operation['code'],
                'name' => $operation['name'],
                'machine_group_id' => $operation['machine_group_id'] ?? null,
                'std_rate_per_hour' => $operation['std_rate_per_hour'] ?? null,
                'setup_minutes' => $operation['setup_minutes'] ?? 0,
                'setup_qty' => $operation['setup_qty'] ?? 0,
                'wastage_pct' => $operation['wastage_pct'] ?? 0,
                'manning_level' => $operation['manning_level'] ?? 1,
                'consumes_web' => $operation['consumes_web'] ?? true,
                'allow_parallel' => $operation['allow_parallel'] ?? false,
                'requires_qc' => $operation['requires_qc'] ?? false,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'machineGroups' => MachineGroup::query()->orderBy('code')->get(['id', 'code', 'name', 'process_type']),
            'productTypes' => array_map(
                fn (ProductType $type): array => ['value' => $type->value, 'label' => $type->label()],
                ProductType::cases(),
            ),
        ];
    }
}
