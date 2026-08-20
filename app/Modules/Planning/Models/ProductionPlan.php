<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int $factory_unit_id
 * @property \Illuminate\Support\Carbon $plan_from
 * @property \Illuminate\Support\Carbon $plan_to
 * @property string $status
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class ProductionPlan extends Model
{
    protected $table = 'production_plans';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'factory_unit_id',
        'plan_from',
        'plan_to',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'factory_unit_id' => 'integer',
            'plan_from' => 'date:Y-m-d',
            'plan_to' => 'date:Y-m-d',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
