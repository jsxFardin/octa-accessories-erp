<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $factory_unit_id
 * @property int $machine_group_id
 * @property int|null $department_id
 * @property string $code
 * @property string $name
 * @property string|null $make
 * @property string|null $model
 * @property string|null $serial_no
 * @property \Illuminate\Support\Carbon|null $commissioned_on
 * @property string|null $web_width_mm
 * @property int|null $max_colours
 * @property string|null $std_rate_per_hour
 * @property string $hourly_rate
 * @property string|null $kw_rating
 * @property string $efficiency_pct
 * @property string $status
 * @property bool $is_active
 */
class Machine extends Model
{
    protected $table = 'machines';

    public $timestamps = false;

    protected $fillable = [
        'factory_unit_id',
        'machine_group_id',
        'department_id',
        'code',
        'name',
        'make',
        'model',
        'serial_no',
        'commissioned_on',
        'web_width_mm',
        'max_colours',
        'std_rate_per_hour',
        'hourly_rate',
        'kw_rating',
        'efficiency_pct',
        'status',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'factory_unit_id' => 'integer',
            'machine_group_id' => 'integer',
            'department_id' => 'integer',
            'commissioned_on' => 'date',
            'web_width_mm' => 'decimal:2',
            'max_colours' => 'integer',
            'std_rate_per_hour' => 'decimal:6',
            'hourly_rate' => 'decimal:4',
            'kw_rating' => 'decimal:3',
            'efficiency_pct' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<MachineGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(MachineGroup::class, 'machine_group_id');
    }

    /** @return BelongsTo<FactoryUnit, $this> */
    public function factoryUnit(): BelongsTo
    {
        return $this->belongsTo(FactoryUnit::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * BR-27 — available minutes for a shift, after planned downtime and this machine's own
     * efficiency. The nameplate rate is not the planning rate.
     */
    public function availableMinutes(float $shiftMinutes, float $plannedDowntimePct = 0.0): float
    {
        return (new \App\Support\Calculators\CapacityCalculator)
            ->availableMinutes($shiftMinutes, $plannedDowntimePct, (float) $this->efficiency_pct);
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
