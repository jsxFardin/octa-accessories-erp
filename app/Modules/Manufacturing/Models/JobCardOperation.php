<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\MasterData\Models\Machine;
use App\Modules\MasterData\Models\MachineGroup;
use App\Modules\Product\Models\RoutingOperation;
use App\Modules\Product\Models\Tool;
use App\Modules\Quality\Models\QcInspection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One step of a job card, executed on one machine.
 *
 * J3 is enforced by the database:
 * `CHECK (good_qty + waste_qty <= input_qty + 0.000001)`. The epsilon absorbs DECIMAL(18,6)
 * accumulation from repeated partial logs across a shift.
 *
 * @property int $id
 * @property int $job_card_id
 * @property int|null $routing_operation_id
 * @property int $sequence_no
 * @property string $code
 * @property string $name
 * @property int|null $machine_group_id
 * @property int|null $machine_id
 * @property int|null $tool_id
 * @property string $planned_qty
 * @property string $input_qty
 * @property string $good_qty
 * @property string $waste_qty
 * @property string $planned_minutes
 * @property string $actual_minutes
 * @property \Illuminate\Support\Carbon|null $scheduled_start
 * @property \Illuminate\Support\Carbon|null $scheduled_finish
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property bool $requires_qc
 * @property string $status
 */
class JobCardOperation extends Model
{
    public const PENDING = 'pending';

    public const READY = 'ready';

    public const IN_PROGRESS = 'in_progress';

    public const PAUSED = 'paused';

    public const COMPLETED = 'completed';

    public const SKIPPED = 'skipped';

    public const CANCELLED = 'cancelled';

    protected $table = 'job_card_operations';

    public $timestamps = false;

    protected $fillable = [
        'job_card_id',
        'routing_operation_id',
        'sequence_no',
        'code',
        'name',
        'machine_group_id',
        'machine_id',
        'tool_id',
        'planned_qty',
        'input_qty',
        'good_qty',
        'waste_qty',
        'planned_minutes',
        'actual_minutes',
        'scheduled_start',
        'scheduled_finish',
        'started_at',
        'finished_at',
        'requires_qc',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'job_card_id' => 'integer',
            'routing_operation_id' => 'integer',
            'sequence_no' => 'integer',
            'machine_group_id' => 'integer',
            'machine_id' => 'integer',
            'tool_id' => 'integer',
            'planned_qty' => 'decimal:6',
            'input_qty' => 'decimal:6',
            'good_qty' => 'decimal:6',
            'waste_qty' => 'decimal:6',
            'planned_minutes' => 'decimal:2',
            'actual_minutes' => 'decimal:2',
            'scheduled_start' => 'datetime',
            'scheduled_finish' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'requires_qc' => 'boolean',
        ];
    }

    /** @return BelongsTo<JobCard, $this> */
    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return BelongsTo<MachineGroup, $this> */
    public function machineGroup(): BelongsTo
    {
        return $this->belongsTo(MachineGroup::class);
    }

    /** @return BelongsTo<Tool, $this> */
    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    /** @return BelongsTo<RoutingOperation, $this> */
    public function routingOperation(): BelongsTo
    {
        return $this->belongsTo(RoutingOperation::class);
    }

    /** @return HasMany<OperationLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(OperationLog::class);
    }

    /**
     * J2 — an operation cannot start before its predecessor is completed, unless the routing
     * marks it `allow_parallel`.
     */
    public function predecessorsComplete(): bool
    {
        if ($this->routingOperation?->allow_parallel) {
            return true;
        }

        return ! self::query()
            ->where('job_card_id', $this->job_card_id)
            ->where('sequence_no', '<', $this->sequence_no)
            ->whereNotIn('status', [self::COMPLETED, self::SKIPPED, self::CANCELLED])
            ->exists();
    }

    /**
     * QC1 — a predecessor flagged `requires_qc` holds its successor until an inspection has
     * accepted its output. The flag was rendered on the floor and in the job card for a while
     * without anything reading it, which made the QC badge advisory: an operator could cut a
     * web the inspector had not passed.
     *
     * A rejected inspection is not a block by itself — rework produces a later accepted one,
     * and it is the latest verdict per operation that counts.
     */
    public function qcClearedUpstream(): bool
    {
        $blocking = self::query()
            ->where('job_card_id', $this->job_card_id)
            ->where('sequence_no', '<', $this->sequence_no)
            ->where('requires_qc', true)
            ->pluck('id');

        if ($blocking->isEmpty()) {
            return true;
        }

        $accepted = QcInspection::query()
            ->where('job_card_id', $this->job_card_id)
            ->where('result', 'accepted')
            ->whereIn('job_card_operation_id', $blocking)
            ->pluck('job_card_operation_id')
            ->unique();

        return $blocking->diff($accepted)->isEmpty();
    }

    /**
     * J2 — the earlier operation that is holding this one up, or null when nothing is.
     *
     * `predecessorsComplete()` answers yes/no; a refusal has to name the step so the floor
     * knows what to chase. Skipped and cancelled operations are not holding anything up.
     */
    public function blockingPredecessor(): ?self
    {
        if ($this->routingOperation?->allow_parallel) {
            return null;
        }

        return self::query()
            ->where('job_card_id', $this->job_card_id)
            ->where('sequence_no', '<', $this->sequence_no)
            ->whereNotIn('status', [self::COMPLETED, self::SKIPPED, self::CANCELLED])
            ->reorder('sequence_no', 'desc')
            ->first();
    }

    /**
     * The operation that hands its output to this one: the nearest earlier step that was not
     * skipped or cancelled. Null on the first operation of a routing, whose input comes from
     * the store rather than from another step.
     */
    public function feedingPredecessor(): ?self
    {
        return self::query()
            ->with('routingOperation')
            ->where('job_card_id', $this->job_card_id)
            ->where('sequence_no', '<', $this->sequence_no)
            ->whereNotIn('status', [self::SKIPPED, self::CANCELLED])
            ->reorder('sequence_no', 'desc')
            ->first();
    }

    /**
     * How much this operation may legitimately be handed, given what the step before it has
     * actually produced — or null when the question does not apply.
     *
     * Null means one of two things, both of which leave the input bounded by the operation's
     * own plan instead:
     *
     *  - **No predecessor.** The first operation is fed from a material issue, not from
     *    another step.
     *  - **A change of unit.** Weaving produces metres and cutting consumes them to make
     *    pieces; 407 m does not cap 30,100 pcs, and pretending it does would block every
     *    real job. The conversion between them is the consumption plan (BR-4 … BR-13)
     *    snapshotted on the card, not a quantity comparison here.
     */
    public function inputAvailableFromPredecessor(): ?float
    {
        $predecessor = $this->feedingPredecessor();

        if ($predecessor === null || ! $this->sharesUnitWith($predecessor)) {
            return null;
        }

        return (float) $predecessor->good_qty;
    }

    /** Statuses in which an operation can still take production. */
    public function acceptsProduction(): bool
    {
        return ! in_array($this->status, [self::COMPLETED, self::SKIPPED, self::CANCELLED], true);
    }

    /**
     * The unit this operation counts in.
     *
     * `routing_operations.consumes_web` is the domain's own discriminator: an operation that
     * consumes the web is measured in metres, one that does not is measured in pieces. It is
     * why an operation's quantities may never be added to another's — 407 m woven and 30,000
     * pcs packed are not 30,407 of anything — and why the figure has to say which it is on
     * every screen that prints it.
     */
    public function unit(): string
    {
        return ($this->routingOperation?->consumes_web ?? false) ? 'm' : 'pcs';
    }

    /** True when this operation's figures may be compared with `$other`'s at all. */
    public function sharesUnitWith(self $other): bool
    {
        return $this->unit() === $other->unit();
    }

    /** J3 — how much this operation may still book without breaching its input. */
    public function remainingOutputAllowance(): float
    {
        return (float) $this->input_qty - (float) $this->good_qty - (float) $this->waste_qty;
    }

    /** @param Builder<$this> $query */
    public function scopeRunnable(Builder $query): void
    {
        $query->whereIn('status', [self::READY, self::IN_PROGRESS, self::PAUSED]);
    }
}
