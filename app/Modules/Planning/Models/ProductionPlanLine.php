<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $production_plan_id
 * @property int|null $sales_order_line_id
 * @property int $product_id
 * @property string $planned_qty
 * @property \Illuminate\Support\Carbon|null $planned_start
 * @property \Illuminate\Support\Carbon|null $planned_finish
 * @property int|null $machine_group_id
 * @property int $priority
 * @property string $status
 */
class ProductionPlanLine extends Model
{
    protected $table = 'production_plan_lines';

    public $timestamps = false;

    protected $fillable = [
        'production_plan_id',
        'sales_order_line_id',
        'product_id',
        'planned_qty',
        'planned_start',
        'planned_finish',
        'machine_group_id',
        'priority',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'production_plan_id' => 'integer',
            'sales_order_line_id' => 'integer',
            'product_id' => 'integer',
            'planned_qty' => 'decimal:6',
            'planned_start' => 'date',
            'planned_finish' => 'date',
            'machine_group_id' => 'integer',
            'priority' => 'integer',
        ];
    }
}
