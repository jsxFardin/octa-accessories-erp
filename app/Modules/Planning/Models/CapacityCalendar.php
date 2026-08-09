<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $machine_id
 * @property int|null $machine_group_id
 * @property \Illuminate\Support\Carbon $calendar_date
 * @property int|null $shift_id
 * @property string $available_minutes
 * @property string $planned_downtime_pct
 * @property bool $is_holiday
 * @property string|null $remarks
 */
class CapacityCalendar extends Model
{
    protected $table = 'capacity_calendars';

    public $timestamps = false;

    protected $fillable = [
        'machine_id',
        'machine_group_id',
        'calendar_date',
        'shift_id',
        'available_minutes',
        'planned_downtime_pct',
        'is_holiday',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'machine_id' => 'integer',
            'machine_group_id' => 'integer',
            'calendar_date' => 'date',
            'shift_id' => 'integer',
            'available_minutes' => 'decimal:2',
            'planned_downtime_pct' => 'decimal:4',
            'is_holiday' => 'boolean',
        ];
    }
}
