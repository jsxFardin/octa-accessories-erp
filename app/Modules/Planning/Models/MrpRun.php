<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $factory_unit_id
 * @property \Illuminate\Support\Carbon $horizon_from
 * @property \Illuminate\Support\Carbon $horizon_to
 * @property \Illuminate\Support\Carbon $run_at
 * @property int|null $run_by
 * @property string $status
 * @property int $shortage_count
 * @property string|null $notes
 */
class MrpRun extends Model
{
    protected $table = 'mrp_runs';

    public $timestamps = false;

    protected $fillable = [
        'factory_unit_id',
        'horizon_from',
        'horizon_to',
        'run_at',
        'run_by',
        'status',
        'shortage_count',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'factory_unit_id' => 'integer',
            'horizon_from' => 'date:Y-m-d',
            'horizon_to' => 'date:Y-m-d',
            'run_at' => 'datetime',
            'run_by' => 'integer',
            'shortage_count' => 'integer',
        ];
    }
}
