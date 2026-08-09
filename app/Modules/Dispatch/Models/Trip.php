<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int $vehicle_id
 * @property int|null $driver_id
 * @property \Illuminate\Support\Carbon $trip_date
 * @property string|null $route_zone
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $start_odometer
 * @property string|null $end_odometer
 * @property string $fuel_cost
 * @property string $status
 * @property string|null $remarks
 */
class Trip extends Model
{
    protected $table = 'trips';

    public $timestamps = false;

    protected $fillable = [
        'number',
        'vehicle_id',
        'driver_id',
        'trip_date',
        'route_zone',
        'started_at',
        'completed_at',
        'start_odometer',
        'end_odometer',
        'fuel_cost',
        'status',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'vehicle_id' => 'integer',
            'driver_id' => 'integer',
            'trip_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'start_odometer' => 'decimal:2',
            'end_odometer' => 'decimal:2',
            'fuel_cost' => 'decimal:4',
        ];
    }
}
