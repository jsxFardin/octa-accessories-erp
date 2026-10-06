<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Planning\Services\OperationScheduler;
use App\Support\Calculators\CapacityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The planning board: machine × day, with BR-27 utilisation computed per cell.
 *
 * Available minutes are not shift minutes. They are shift minutes discounted by planned
 * downtime and by the machine's own efficiency, which is why a board built on nameplate
 * capacity always over-promises.
 */
class PlanningBoardController extends Controller
{
    public function __construct(
        private readonly CapacityCalculator $capacity,
        private readonly OperationScheduler $scheduler,
    ) {}

    public function index(Request $request): Response
    {
        $from = CarbonImmutable::parse($request->query('from', now()->toDateString()))->startOfDay();
        $days = min(21, max(5, (int) $request->query('days', '10')));
        $dates = collect(range(0, $days - 1))->map(fn (int $i): string => $from->addDays($i)->toDateString());

        $machines = DB::table('machines as m')
            ->join('machine_groups as g', 'g.id', '=', 'm.machine_group_id')
            ->where('m.is_active', true)
            ->when($request->query('group'), fn ($q, $id) => $q->where('m.machine_group_id', $id))
            ->orderBy('g.code')->orderBy('m.code')
            ->get(['m.id', 'm.code', 'm.name', 'm.efficiency_pct', 'm.status', 'm.machine_group_id', 'g.code as group_code', 'g.name as group_name']);

        $load = DB::table('v_machine_load')
            ->whereIn('load_date', $dates)
            ->get()
            ->groupBy(fn ($row): string => $row->machine_id.'|'.$row->load_date);

        $calendars = DB::table('capacity_calendars')
            ->whereIn('calendar_date', $dates)
            ->get()
            ->groupBy(fn ($row): string => $row->machine_id.'|'.$row->calendar_date);

        $cells = [];

        foreach ($machines as $machine) {
            foreach ($dates as $date) {
                $key = $machine->id.'|'.$date;
                $calendar = $calendars->get($key)?->first();

                // With no calendar row, assume a single 8-hour shift: a machine with no
                // published calendar is still a machine, and a board that silently shows zero
                // capacity reads as "fully free".
                $shiftMinutes = (float) ($calendar->available_minutes ?? 480);
                $plannedDowntime = (float) ($calendar->planned_downtime_pct ?? 0);
                $isHoliday = (bool) ($calendar->is_holiday ?? false);

                $available = $isHoliday ? 0.0 : $this->capacity->availableMinutes(
                    $shiftMinutes,
                    $plannedDowntime,
                    (float) $machine->efficiency_pct,
                );

                $loadMinutes = (float) ($load->get($key)?->sum('load_minutes') ?? 0);

                $cells[] = [
                    'machine_id' => $machine->id,
                    'date' => $date,
                    'is_holiday' => $isHoliday,
                    'operations' => (int) ($load->get($key)?->sum('operation_count') ?? 0),
                    ...$this->capacity->utilisation($loadMinutes, $available),
                ];
            }
        }

        return Inertia::render('Planning/Board', [
            'machines' => $machines,
            'dates' => $dates,
            'cells' => $cells,
            'filters' => ['from' => $from->toDateString(), 'days' => $days, 'group' => $request->query('group')],
            'groups' => DB::table('machine_groups')->orderBy('code')->get(['id', 'code', 'name']),
            // `machine_group_id` and `planned_minutes` ride along because the planner needs
            // both to choose: the group says which machines may take the step at all, the
            // minutes say whether the day it is dropped on can hold it.
            'unscheduled' => $this->unscheduled()
                ->leftJoin('machine_groups as mg', 'mg.id', '=', 'jco.machine_group_id')
                ->leftJoin('routing_operations as ro', 'ro.id', '=', 'jco.routing_operation_id')
                ->orderBy('jc.due_date')
                ->orderBy('jc.id')
                ->orderBy('jco.sequence_no')
                ->limit(50)
                ->get([
                    'jco.id', 'jco.code', 'jco.name', 'jco.sequence_no', 'jco.planned_qty',
                    'jco.planned_minutes', 'jco.machine_group_id', 'mg.name as machine_group',
                    'jc.id as job_card_id', 'jc.number', 'jc.due_date', 'jc.status as job_card_status',
                    // Metres or pieces: a quantity without its unit is the misreading the
                    // job card page already had to fix.
                    DB::raw("CASE WHEN COALESCE(ro.consumes_web, 1) THEN 'm' ELSE 'pcs' END as unit"),
                    // The day the step ahead of it is planned to finish. The scheduler refuses
                    // anything earlier (`assertNotBeforePredecessor`), so the board must not
                    // suggest it; a step that may run in parallel has no such day.
                    DB::raw("CASE WHEN COALESCE(ro.allow_parallel, 0) THEN NULL ELSE (
                        SELECT DATE(MAX(p.scheduled_finish)) FROM job_card_operations p
                        WHERE p.job_card_id = jco.job_card_id
                          AND p.sequence_no < jco.sequence_no
                          AND p.status NOT IN ('skipped', 'cancelled')
                          AND p.scheduled_finish IS NOT NULL
                    ) END as not_before"),
                ]),
            // The list above stops at fifty, soonest due first. Without the full count it
            // read as "this is everything", and the fifty-first step was simply not there.
            'unscheduledTotal' => $this->unscheduled()->count(),
            // What is already on the board, so a plan can be moved or taken off it. Without
            // this list the only way to correct a placement was to have never made it.
            'scheduled' => $this->scheduled()
                ->whereIn(DB::raw('DATE(jco.scheduled_start)'), $dates)
                ->orderBy('jco.scheduled_start')
                ->get([
                    'jco.id', 'jco.name', 'jco.sequence_no', 'jco.planned_minutes',
                    'jco.machine_id', 'jco.scheduled_start', 'm.code as machine',
                    'jc.id as job_card_id', 'jc.number', 'jc.due_date',
                ]),
            // Where the plan is when this window has none of it. A board of empty cells
            // with "nothing scheduled" under it read as "nothing is planned anywhere".
            'summary' => [
                'scheduled_total' => $this->scheduled()->count(),
                'next_scheduled_on' => $this->scheduled()
                    ->whereDate('jco.scheduled_start', '>', $dates->last())
                    ->min(DB::raw('DATE(jco.scheduled_start)')),
                'previous_scheduled_on' => $this->scheduled()
                    ->whereDate('jco.scheduled_start', '<', $dates->first())
                    ->max(DB::raw('DATE(jco.scheduled_start)')),
                'waiting_cards' => $this->unscheduled()->distinct()->count('jc.id'),
                'waiting_late' => $this->unscheduled()->whereDate('jc.due_date', '<', now()->toDateString())->distinct()->count('jc.id'),
            ],
        ]);
    }

    /** Steps that are on the board and can still be moved: pending or ready, with a machine and a day. */
    private function scheduled(): \Illuminate\Database\Query\Builder
    {
        return DB::table('job_card_operations as jco')
            ->join('job_cards as jc', 'jc.id', '=', 'jco.job_card_id')
            ->join('machines as m', 'm.id', '=', 'jco.machine_id')
            ->whereNotNull('jco.scheduled_start')
            ->whereIn('jco.status', ['pending', 'ready']);
    }

    /** Open steps of live job cards that have no machine and day yet. */
    private function unscheduled(): \Illuminate\Database\Query\Builder
    {
        return DB::table('job_card_operations as jco')
            ->join('job_cards as jc', 'jc.id', '=', 'jco.job_card_id')
            ->whereNull('jco.scheduled_start')
            ->whereIn('jco.status', ['pending', 'ready'])
            ->whereNotIn('jc.status', ['closed', 'cancelled']);
    }

    /**
     * Put an operation on a machine on a day.
     *
     * The rules live in `OperationScheduler` because MRP and any later auto-scheduler have to
     * obey the same ones; this only turns a request into a call and a refusal into a message.
     */
    public function schedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'operation_id' => ['required', 'integer', 'exists:job_card_operations,id'],
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'date' => ['required', 'date'],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $operation = JobCardOperation::query()->with(['jobCard', 'routingOperation'])->findOrFail($data['operation_id']);

        $this->scheduler->schedule(
            $operation,
            (int) $data['machine_id'],
            (string) $data['date'],
            $data['override_reason'] ?? null,
        );

        return back()->with('success', "{$operation->name} scheduled.");
    }

    /** Take it back off the board. */
    public function unschedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'operation_id' => ['required', 'integer', 'exists:job_card_operations,id'],
        ]);

        $operation = JobCardOperation::query()->with('jobCard')->findOrFail($data['operation_id']);

        $this->scheduler->unschedule($operation);

        return back()->with('success', "{$operation->name} taken off the board.");
    }
}
