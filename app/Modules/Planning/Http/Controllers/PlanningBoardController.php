<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Calculators\CapacityCalculator;
use Carbon\CarbonImmutable;
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
    public function __construct(private readonly CapacityCalculator $capacity) {}

    public function __invoke(Request $request): Response
    {
        $from = CarbonImmutable::parse($request->query('from', now()->toDateString()))->startOfDay();
        $days = min(21, max(5, (int) $request->query('days', '10')));
        $dates = collect(range(0, $days - 1))->map(fn (int $i): string => $from->addDays($i)->toDateString());

        $machines = DB::table('machines as m')
            ->join('machine_groups as g', 'g.id', '=', 'm.machine_group_id')
            ->where('m.is_active', true)
            ->when($request->query('group'), fn ($q, $id) => $q->where('m.machine_group_id', $id))
            ->orderBy('g.code')->orderBy('m.code')
            ->get(['m.id', 'm.code', 'm.name', 'm.efficiency_pct', 'm.status', 'g.code as group_code', 'g.name as group_name']);

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
            'unscheduled' => DB::table('job_card_operations as jco')
                ->join('job_cards as jc', 'jc.id', '=', 'jco.job_card_id')
                ->whereNull('jco.scheduled_start')
                ->whereNotIn('jc.status', ['closed', 'cancelled'])
                ->orderBy('jc.due_date')
                ->limit(30)
                ->get(['jco.id', 'jco.code', 'jco.name', 'jco.planned_qty', 'jc.id as job_card_id', 'jc.number', 'jc.due_date']),
        ]);
    }
}
