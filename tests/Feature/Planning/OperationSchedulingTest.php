<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCardOperation;
use Illuminate\Support\Facades\DB;

/**
 * The planning board can plan.
 *
 * `scheduled_start`, `scheduled_finish` and the planner's choice of `machine_id` were columns
 * nothing in the application ever wrote. `v_machine_load` selects
 * `WHERE scheduled_start IS NOT NULL`, so every cell on the board was empty by construction;
 * the job card told its creator to "schedule its operations to plan it" with no screen that
 * could; and `FloorQueueController` ordered the operator's queue by the same always-NULL
 * column, so the floor received its work in whatever order MySQL happened to return.
 */
beforeEach(function (): void {
    $this->planner = User::query()->where('email', 'planner@octapussolution.com')->firstOrFail();

    $this->operation = JobCardOperation::query()
        ->whereIn('status', [JobCardOperation::PENDING, JobCardOperation::READY])
        ->orderBy('sequence_no')
        ->firstOrFail();

    // The seed carries no machines, so the group and the machine this plans onto are made here.
    $this->groupId = (int) DB::table('machine_groups')->value('id');

    // The seed writes `scheduled_start` straight into the table (`DemoDataSeeder`), which is
    // why the board looked populated while nothing in the application could populate it. These
    // tests start from a step that is genuinely unplanned.
    $this->operation->forceFill([
        'machine_group_id' => $this->groupId,
        'planned_minutes' => 120,
        'machine_id' => null,
        'scheduled_start' => null,
        'scheduled_finish' => null,
    ])->save();

    $this->machineId = (int) DB::table('machines')->insertGetId([
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'machine_group_id' => $this->groupId,
        'code' => 'PLAN-1',
        'name' => 'Planning test machine',
        'efficiency_pct' => 100,
    ]);

    $this->day = now()->addDay()->toDateString();

    $this->schedule = fn (array $overrides = []) => $this->actingAs($this->planner)->post('/planning/schedule', [
        'operation_id' => $this->operation->id,
        'machine_id' => $this->machineId,
        'date' => $this->day,
        ...$overrides,
    ]);
});

it('planning: writes the machine and both scheduled dates', function (): void {
    ($this->schedule)()->assertRedirect();

    $this->operation->refresh();

    expect((int) $this->operation->machine_id)->toBe($this->machineId)
        ->and($this->operation->scheduled_start)->not->toBeNull()
        ->and($this->operation->scheduled_finish)->not->toBeNull()
        // The finish is derived from planned_minutes, not typed: it is what the next step's
        // earliest start and the day's load are both measured against.
        ->and($this->operation->scheduled_start->diffInMinutes($this->operation->scheduled_finish))
        ->toEqualWithDelta(120, 0.01);
});

it('planning: puts the operation on the board the planner is looking at', function (): void {
    // The regression this exists for: `v_machine_load` reads `scheduled_start IS NOT NULL`,
    // so with nothing writing that column every cell was empty however much work existed.
    ($this->schedule)();

    $load = DB::table('v_machine_load')
        ->where('machine_id', $this->machineId)
        ->whereDate('load_date', $this->day)
        ->first();

    expect($load)->not->toBeNull()
        ->and((float) $load->load_minutes)->toEqualWithDelta(120, 0.01)
        ->and((int) $load->operation_count)->toBe(1);
});

it('planning: refuses a machine outside the operation\'s group', function (): void {
    $otherGroup = (int) DB::table('machine_groups')->where('id', '!=', $this->groupId)->value('id');

    $wrong = DB::table('machines')->insertGetId([
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'machine_group_id' => $otherGroup,
        'code' => 'PLAN-WRONG',
        'name' => 'A machine that cannot run this step',
    ]);

    ($this->schedule)(['machine_id' => $wrong])->assertSessionHasErrors('machine_id');

    expect($this->operation->refresh()->scheduled_start)->toBeNull();
});

it('planning: refuses a retired machine', function (): void {
    DB::table('machines')->where('id', $this->machineId)->update(['is_active' => false]);

    ($this->schedule)()->assertSessionHasErrors('machine_id');
});

it('planning: BR-27 — refuses to over-commit a machine without a reason, and allows it with one', function (): void {
    // One 8-hour shift at 100% efficiency is 480 minutes. Four two-hour steps fill it.
    $fillers = DB::table('job_card_operations')
        ->where('id', '!=', $this->operation->id)
        ->limit(4)
        ->pluck('id');

    DB::table('job_card_operations')
        ->whereIn('id', $fillers)
        ->update([
            'machine_id' => $this->machineId,
            'planned_minutes' => 120,
            'status' => JobCardOperation::PENDING,
            'scheduled_start' => $this->day.' 08:00:00',
        ]);

    ($this->schedule)()->assertSessionHasErrors('scheduled_start');

    expect($this->operation->refresh()->scheduled_start)->toBeNull();

    ($this->schedule)(['override_reason' => 'Buyer pulled the date forward; running the night shift.'])
        ->assertSessionHasNoErrors();

    expect($this->operation->refresh()->scheduled_start)->not->toBeNull();
});

it('planning: keeps the override reason on the audit trail', function (): void {
    ($this->schedule)(['override_reason' => 'Approved by the MD.']);

    $entry = DB::table('audit_logs')
        ->where('auditable_type', JobCardOperation::class)
        ->where('auditable_id', $this->operation->id)
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->new_values)->toContain('Approved by the MD.')
        ->and($entry->new_values)->toContain('scheduled');
});

it('planning: J2 — refuses to schedule a step before the one that feeds it finishes', function (): void {
    $successor = JobCardOperation::query()
        ->where('job_card_id', $this->operation->job_card_id)
        ->where('sequence_no', '>', $this->operation->sequence_no)
        ->orderBy('sequence_no')
        ->first();

    if ($successor === null) {
        $this->markTestSkipped('The chosen job card has a single operation.');
    }

    if ($successor->routingOperation?->allow_parallel) {
        $this->markTestSkipped('This step is marked parallel; J2 deliberately does not hold it.');
    }

    ($this->schedule)();

    $successor->forceFill(['machine_group_id' => $this->groupId, 'planned_minutes' => 60])->save();

    $this->actingAs($this->planner)->post('/planning/schedule', [
        'operation_id' => $successor->id,
        'machine_id' => $this->machineId,
        // The day *before* its predecessor is scheduled to run.
        'date' => now()->toDateString(),
    ])->assertSessionHasErrors('scheduled_start');
});

it('planning: refuses to schedule a step that has already started', function (): void {
    $this->operation->forceFill(['status' => JobCardOperation::IN_PROGRESS])->save();

    ($this->schedule)()->assertSessionHasErrors('operation_id');
});

it('planning: moving a step onto its own day does not count it against itself', function (): void {
    // The regression this exists for: an operation still sitting on the day it is being moved
    // to would otherwise be counted twice, so a move that changes nothing reads as double the
    // load and is refused.
    $this->operation->forceFill(['planned_minutes' => 400])->save();

    ($this->schedule)()->assertSessionHasNoErrors();
    ($this->schedule)()->assertSessionHasNoErrors();
});

it('planning: taking an operation off the board clears the machine with the dates', function (): void {
    ($this->schedule)();

    $this->actingAs($this->planner)
        ->post('/planning/unschedule', ['operation_id' => $this->operation->id])
        ->assertRedirect();

    $this->operation->refresh();

    // The machine goes with the dates. Left behind, the step belongs to no day and is still
    // claimed — invisible on the board and blocking nothing it can be seen to block.
    expect($this->operation->machine_id)->toBeNull()
        ->and($this->operation->scheduled_start)->toBeNull()
        ->and($this->operation->scheduled_finish)->toBeNull();
});

it('planning: refuses both writes to someone who may only read the board', function (): void {
    $reader = User::query()->where('email', 'operator@octapussolution.com')->firstOrFail();

    expect($reader->hasPermission('production_plan.update'))->toBeFalse();

    $this->actingAs($reader)->post('/planning/schedule', [
        'operation_id' => $this->operation->id,
        'machine_id' => $this->machineId,
        'date' => $this->day,
    ])->assertForbidden();

    $this->actingAs($reader)
        ->post('/planning/unschedule', ['operation_id' => $this->operation->id])
        ->assertForbidden();
});
