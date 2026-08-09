<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $machine_id
 * @property int|null $job_card_operation_id
 * @property int $downtime_reason_id
 * @property int|null $shift_id
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property string|null $minutes
 * @property int|null $reported_by
 * @property string|null $remarks
 */
class DowntimeLog extends Model
{
    protected $table = 'downtime_logs';

    public $timestamps = false;

    protected $fillable = [
        'machine_id',
        'job_card_operation_id',
        'downtime_reason_id',
        'shift_id',
        'started_at',
        'ended_at',
        'minutes',
        'reported_by',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'machine_id' => 'integer',
            'job_card_operation_id' => 'integer',
            'downtime_reason_id' => 'integer',
            'shift_id' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'minutes' => 'decimal:2',
            'reported_by' => 'integer',
        ];
    }
}
