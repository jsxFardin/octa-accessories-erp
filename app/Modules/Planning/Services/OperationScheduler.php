<?php

declare(strict_types=1);

namespace App\Modules\Planning\Services;

use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Support\Audit\AuditLogger;
use App\Support\Calculators\CapacityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Putting an operation on a machine on a day — the one write the planning board never had.
 *
 * `scheduled_start`, `scheduled_finish` and the planner's choice of `machine_id` were columns
 * nothing in the application ever set. The board rendered `v_machine_load`, which reads
 * `WHERE scheduled_start IS NOT NULL`, so every cell was empty by construction; the job card
 * told its creator to "schedule its operations to plan it" with no screen that could; and
 * `FloorQueueController` ordered the operator's queue by a column that was always NULL, which
 * is why the floor got its work in whatever order MySQL happened to return.
 *
 * The rules live here rather than in the controller because MRP and any later auto-scheduler
 * have to obey the same ones (08-architecture §1, boundary rule 1).
 */
class OperationScheduler
{
    /** A day with no published calendar row is one 8-hour shift, as the board assumes. */
    private const DEFAULT_SHIFT_MINUTES = 480.0;

    public function __construct(
        private readonly CapacityCalculator $capacity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Place one operation on one machine on one day.
     *
     * @param  string  $date  Y-m-d; the board schedules by day, not by the minute
     *
     * @throws ValidationException when a rule refuses and no override reason was given
     */
    public function schedule(
        JobCardOperation $operation,
        int $machineId,
        string $date,
        ?string $overrideReason = null,
    ): JobCardOperation {
        return DB::transaction(function () use ($operation, $machineId, $date, $overrideReason): JobCardOperation {
            /** @var JobCardOperation $locked */
            $locked = JobCardOperation::query()->lockForUpdate()->findOrFail($operation->getKey());

            $machine = $this->assertMachineFits($locked, $machineId);
            $this->assertOperationIsPlannable($locked);

            $day = CarbonImmutable::parse($date)->startOfDay();

            $this->assertNotBeforePredecessor($locked, $day);
            $this->assertDayIsWorkable($locked, $machine, $day, $overrideReason);
            $this->assertFitsCapacity($locked, $machine, $day, $overrideReason);

            $before = [
                'machine_id' => $locked->machine_id,
                'scheduled_start' => $locked->scheduled_start?->toIso8601String(),
            ];

            // The board plans by the day; the finish is what the day's load and the next
            // operation's earliest start are both measured against, so it is derived from
            // `planned_minutes` rather than left for someone to type.
            $start = $day->setTime(8, 0);

            $locked->forceFill([
                'machine_id' => $machine->id,
                'scheduled_start' => $start,
                'scheduled_finish' => $start->addMinutes((float) $locked->planned_minutes),
            ])->save();

            // `audit_logs.event` is a CHECK-constrained vocabulary, and a schedule genuinely
            // is an update to this row — the detail that makes it a scheduling decision lives
            // in the values, where the trail can be read.
            $this->audit->record($locked, 'updated', $before, [
                'action' => 'scheduled',
                'machine_id' => $machine->id,
                'machine' => $machine->code,
                'scheduled_start' => $locked->scheduled_start?->toIso8601String(),
                'scheduled_finish' => $locked->scheduled_finish?->toIso8601String(),
                'planned_minutes' => (float) $locked->planned_minutes,
                // BR-27 — an override is the whole reason the refusal was allowed through, so
                // it is the part of this record that has to survive.
                'override_reason' => $overrideReason,
            ]);

            return $locked;
        });
    }

    /**
     * Take an operation back off the board.
     *
     * The machine is cleared with the dates. Leaving it behind would keep the operation out of
     * every other machine's queue while belonging to no day — invisible on the board and still
     * claimed.
     */
    public function unschedule(JobCardOperation $operation): JobCardOperation
    {
        return DB::transaction(function () use ($operation): JobCardOperation {
            /** @var JobCardOperation $locked */
            $locked = JobCardOperation::query()->lockForUpdate()->findOrFail($operation->getKey());

            $this->assertOperationIsPlannable($locked);

            $before = [
                'machine_id' => $locked->machine_id,
                'scheduled_start' => $locked->scheduled_start?->toIso8601String(),
            ];

            $locked->forceFill([
                'machine_id' => null,
                'scheduled_start' => null,
                'scheduled_finish' => null,
            ])->save();

            $this->audit->record($locked, 'updated', $before, [
                'action' => 'unscheduled',
                'machine_id' => null,
                'scheduled_start' => null,
            ]);

            return $locked;
        });
    }

    /**
     * What a machine has on a day, and what it has left — the figure the board colours a cell
     * with, computed for one cell so a refusal can quote it.
     *
     * @return array{available: float, load: float, utilisation_pct: float, over_capacity: bool, spare_minutes: float, is_holiday: bool}
     */
    public function cell(int $machineId, CarbonImmutable $day, ?int $excludingOperationId = null): array
    {
        $machine = DB::table('machines')->where('id', $machineId)->first(['id', 'efficiency_pct']);

        $calendar = DB::table('capacity_calendars')
            ->where('machine_id', $machineId)
            ->whereDate('calendar_date', $day->toDateString())
            ->first(['available_minutes', 'planned_downtime_pct', 'is_holiday']);

        $isHoliday = (bool) ($calendar->is_holiday ?? false);

        $available = $isHoliday ? 0.0 : $this->capacity->availableMinutes(
            (float) ($calendar->available_minutes ?? self::DEFAULT_SHIFT_MINUTES),
            (float) ($calendar->planned_downtime_pct ?? 0),
            (float) ($machine->efficiency_pct ?? 100),
        );

        // The operation being moved must not be counted against the day it is moving to while
        // it still sits there — rescheduling a step onto its own day would otherwise read as
        // double the load and refuse a move that changes nothing.
        $load = (float) JobCardOperation::query()
            ->where('machine_id', $machineId)
            ->whereNotNull('scheduled_start')
            ->whereDate('scheduled_start', $day->toDateString())
            ->whereIn('status', [
                JobCardOperation::PENDING,
                JobCardOperation::READY,
                JobCardOperation::IN_PROGRESS,
            ])
            ->when($excludingOperationId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->sum('planned_minutes');

        return [...$this->capacity->utilisation($load, $available), 'is_holiday' => $isHoliday];
    }

    /**
     * A press cannot weave. The routing names a machine *group*; the planner picks a machine
     * inside it, and picking outside it is a plan that cannot run.
     */
    private function assertMachineFits(JobCardOperation $operation, int $machineId): object
    {
        $machine = DB::table('machines')
            ->where('id', $machineId)
            ->first(['id', 'code', 'name', 'is_active', 'status', 'machine_group_id', 'efficiency_pct']);

        if ($machine === null || ! $machine->is_active || $machine->status === 'retired') {
            throw ValidationException::withMessages([
                'machine_id' => 'That machine is retired or inactive and cannot take work.',
            ]);
        }

        if ($operation->machine_group_id !== null
            && (int) $machine->machine_group_id !== (int) $operation->machine_group_id) {
            $group = DB::table('machine_groups')->where('id', $operation->machine_group_id)->value('name');

            throw ValidationException::withMessages([
                'machine_id' => "{$operation->name} runs on {$group}. {$machine->code} is not in that group.",
            ]);
        }

        return $machine;
    }

    /** Planning is for work that has not happened. A running or finished step is a record. */
    private function assertOperationIsPlannable(JobCardOperation $operation): void
    {
        if (! in_array($operation->status, [JobCardOperation::PENDING, JobCardOperation::READY], true)) {
            throw ValidationException::withMessages([
                'operation_id' => sprintf(
                    '%s is %s. Only a pending or ready step can be scheduled.',
                    $operation->name,
                    str_replace('_', ' ', $operation->status),
                ),
            ]);
        }

        $status = $operation->jobCard?->status;

        if (in_array($status, [JobCard::CLOSED, JobCard::CANCELLED], true)) {
            throw ValidationException::withMessages([
                'operation_id' => "That job card is {$status}; its operations cannot be scheduled.",
            ]);
        }
    }

    /**
     * J2 in the plan, not only on the floor.
     *
     * The terminal already refuses to start a step whose predecessor is unfinished. Scheduling
     * cutting before weaving passes that check at planning time and fails at 6am on the floor,
     * which is the wrong hour to discover it.
     */
    private function assertNotBeforePredecessor(JobCardOperation $operation, CarbonImmutable $day): void
    {
        if ($operation->routingOperation?->allow_parallel) {
            return;
        }

        $predecessor = JobCardOperation::query()
            ->where('job_card_id', $operation->job_card_id)
            ->where('sequence_no', '<', $operation->sequence_no)
            ->whereNotIn('status', [JobCardOperation::SKIPPED, JobCardOperation::CANCELLED])
            ->whereNotNull('scheduled_finish')
            ->reorder('scheduled_finish', 'desc')
            ->first();

        if ($predecessor === null) {
            return;
        }

        if ($day->endOfDay()->lessThan($predecessor->scheduled_finish)) {
            throw ValidationException::withMessages([
                'scheduled_start' => sprintf(
                    'J2: %s (step %d) is not scheduled to finish until %s. This step cannot start before it.',
                    $predecessor->name,
                    $predecessor->sequence_no,
                    $predecessor->scheduled_finish->format('D j M'),
                ),
            ]);
        }
    }

    /** A holiday is a closed factory. Planning into one is possible, but it is a decision. */
    private function assertDayIsWorkable(
        JobCardOperation $operation,
        object $machine,
        CarbonImmutable $day,
        ?string $overrideReason,
    ): void {
        $cell = $this->cell((int) $machine->id, $day, (int) $operation->getKey());

        if ($cell['is_holiday'] && blank($overrideReason)) {
            throw ValidationException::withMessages([
                'scheduled_start' => sprintf(
                    '%s is a holiday for %s. Record why the machine is running, or pick another day.',
                    $day->format('D j M'),
                    $machine->code,
                ),
            ]);
        }
    }

    /**
     * BR-27 — the board blocks scheduling past 100% unless the planner overrides with a
     * reason. An over-committed machine is a delivery date that has already slipped; the
     * refusal quotes the figures so the planner can decide rather than guess.
     */
    private function assertFitsCapacity(
        JobCardOperation $operation,
        object $machine,
        CarbonImmutable $day,
        ?string $overrideReason,
    ): void {
        if (filled($overrideReason)) {
            return;
        }

        $cell = $this->cell((int) $machine->id, $day, (int) $operation->getKey());
        $wanted = (float) $operation->planned_minutes;

        if ($cell['load'] + $wanted > $cell['available'] + 0.0001) {
            throw ValidationException::withMessages([
                'scheduled_start' => sprintf(
                    'BR-27: %s has %s of %s minutes free on %s, and this step needs %s. Pick another day or machine, or record why it is being over-committed.',
                    $machine->code,
                    $this->minutes($cell['spare_minutes']),
                    $this->minutes($cell['available']),
                    $day->format('D j M'),
                    $this->minutes($wanted),
                ),
            ]);
        }
    }

    private function minutes(float $value): string
    {
        return number_format(max(0, $value), 0);
    }
}
