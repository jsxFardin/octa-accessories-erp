<?php

declare(strict_types=1);

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $routing_id
 * @property int $sequence_no
 * @property string $code
 * @property string $name
 * @property int|null $machine_group_id
 * @property int|null $department_id
 * @property string|null $std_rate_per_hour
 * @property string $setup_minutes
 * @property string $setup_qty
 * @property string $wastage_pct
 * @property string $manning_level
 * @property bool $consumes_web
 * @property bool $allow_parallel
 * @property bool $requires_qc
 */
class RoutingOperation extends Model
{
    protected $table = 'routing_operations';

    public $timestamps = false;

    protected $fillable = [
        'routing_id',
        'sequence_no',
        'code',
        'name',
        'machine_group_id',
        'department_id',
        'std_rate_per_hour',
        'setup_minutes',
        'setup_qty',
        'wastage_pct',
        'manning_level',
        'consumes_web',
        'allow_parallel',
        'requires_qc',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'routing_id' => 'integer',
            'sequence_no' => 'integer',
            'machine_group_id' => 'integer',
            'department_id' => 'integer',
            'std_rate_per_hour' => 'decimal:6',
            'setup_minutes' => 'decimal:2',
            'setup_qty' => 'decimal:6',
            'wastage_pct' => 'decimal:4',
            'manning_level' => 'decimal:4',
            'consumes_web' => 'boolean',
            'allow_parallel' => 'boolean',
            'requires_qc' => 'boolean',
        ];
    }

    /** @return BelongsTo<Routing, $this> */
    public function routing(): BelongsTo
    {
        return $this->belongsTo(Routing::class);
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\MachineGroup, $this> */
    public function machineGroup(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\MachineGroup::class, 'machine_group_id');
    }

    /**
     * Lift into the calculators' value object, folding in the rates of a specific machine
     * when one is nominated — BR-16 allows a per-machine override of the group rate.
     */
    public function toCalculatorStep(?\App\Modules\MasterData\Models\Machine $machine = null): \App\Support\Calculators\RoutingStep
    {
        return new \App\Support\Calculators\RoutingStep(
            sequenceNo: (int) $this->sequence_no,
            code: (string) $this->code,
            name: (string) $this->name,
            wastagePct: (float) $this->wastage_pct,
            setupQty: (float) $this->setup_qty,
            consumesWeb: (bool) $this->consumes_web,
            stdRatePerHour: (float) ($machine?->std_rate_per_hour ?? $this->std_rate_per_hour ?? 0),
            setupMinutes: (float) $this->setup_minutes,
            manningLevel: (float) $this->manning_level,
            machineHourlyRate: (float) ($machine?->hourly_rate ?? 0),
            machineKwRating: (float) ($machine?->kw_rating ?? 0),
            allowParallel: (bool) $this->allow_parallel,
        );
    }
}
