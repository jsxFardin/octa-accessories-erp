<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\FactoryUnit;
use App\Modules\MasterData\Models\Machine;
use App\Modules\MasterData\Models\MachineGroup;
use App\Support\Http\ListsResources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Machines carry everything BR-16, BR-18 and BR-27 need — standard rate, hourly rate, kW
 * rating and efficiency — on the machine rather than in a config file. `web_width_mm` and
 * `max_colours` let the planner reject an impossible assignment before it reaches the floor.
 */
class MachineController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = Machine::query()->with(['group:id,code,name,process_type', 'factoryUnit:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['code', 'name', 'make', 'model'],
            filters: ['group' => 'machine_group_id', 'status' => 'status', 'active' => 'is_active'],
            sortable: ['code', 'name', 'status', 'efficiency_pct'],
            defaultSort: 'code',
        );

        return Inertia::render('MasterData/Machines/Index', [
            'machines' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Machine $machine): array => [
                    ...$machine->only([
                        'id', 'code', 'name', 'make', 'model', 'web_width_mm', 'max_colours',
                        'std_rate_per_hour', 'hourly_rate', 'kw_rating', 'efficiency_pct',
                        'status', 'is_active',
                    ]),
                    'group' => $machine->group?->name,
                    'process_type' => $machine->group?->process_type,
                ],
            ),
            'filters' => $this->listingFilters($request, ['group', 'status', 'active']),
            'groups' => MachineGroup::query()->orderBy('code')->get(['id', 'code', 'name', 'process_type']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/Machines/Form', ['machine' => null, ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $machine = Machine::query()->create($this->validated($request));

        return redirect()->route('machines.show', $machine)->with('success', "Machine {$machine->code} created.");
    }

    public function show(Machine $machine): Response
    {
        $machine->load(['group', 'factoryUnit', 'department']);

        return Inertia::render('MasterData/Machines/Show', [
            'machine' => $machine,
            // BR-27 — scheduled minutes per day, from the view the planning board also reads.
            'load' => DB::table('v_machine_load')->where('machine_id', $machine->id)
                ->orderBy('load_date')->limit(30)->get(),
            'downtime' => DB::table('downtime_logs as dl')
                ->leftJoin('downtime_reasons as dr', 'dr.id', '=', 'dl.downtime_reason_id')
                ->where('dl.machine_id', $machine->id)
                ->orderByDesc('dl.started_at')->limit(20)
                ->get(['dl.id', 'dl.started_at', 'dl.ended_at', 'dl.minutes', 'dr.name as reason', 'dr.category']),
        ]);
    }

    public function edit(Machine $machine): Response
    {
        return Inertia::render('MasterData/Machines/Form', ['machine' => $machine, ...$this->options()]);
    }

    public function update(Request $request, Machine $machine): RedirectResponse
    {
        $machine->update($this->validated($request, $machine));

        return redirect()->route('machines.show', $machine)->with('success', "Machine {$machine->code} updated.");
    }

    public function destroy(Machine $machine): RedirectResponse
    {
        $machine->update(['is_active' => false, 'status' => 'retired']);

        return redirect()->route('machines.index')->with('success', "Machine {$machine->code} retired.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Machine $machine = null): array
    {
        return $request->validate([
            'factory_unit_id' => ['required', 'integer', 'exists:factory_units,id'],
            'machine_group_id' => ['required', 'integer', 'exists:machine_groups,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'code' => ['required', 'string', 'max:30', Rule::unique('machines', 'code')->ignore($machine?->id)],
            'name' => ['required', 'string', 'max:120'],
            'make' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'serial_no' => ['nullable', 'string', 'max:80'],
            'commissioned_on' => ['nullable', 'date'],
            'web_width_mm' => ['nullable', 'numeric', 'gt:0'],
            'max_colours' => ['nullable', 'integer', 'min:1'],
            'std_rate_per_hour' => ['nullable', 'numeric', 'gt:0'],
            'hourly_rate' => ['numeric', 'min:0'],
            'kw_rating' => ['nullable', 'numeric', 'min:0'],
            // BR-27 divides by this; 0 would make the machine infinitely fast.
            'efficiency_pct' => ['numeric', 'gt:0', 'max:100'],
            'status' => ['required', Rule::in(['available', 'running', 'maintenance', 'breakdown', 'retired'])],
            'is_active' => ['boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'groups' => MachineGroup::query()->orderBy('code')->get(['id', 'code', 'name', 'process_type']),
            'units' => FactoryUnit::query()->orderBy('code')->get(['id', 'code', 'name']),
            'departments' => Department::query()->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }
}
