<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sales_order_line_id
 * @property int $sequence_no
 * @property string $qty
 * @property \Illuminate\Support\Carbon $due_date
 * @property string $delivered_qty
 */
class SoDeliverySchedule extends Model
{
    protected $table = 'so_delivery_schedules';

    public $timestamps = false;

    protected $fillable = [
        'sales_order_line_id',
        'sequence_no',
        'qty',
        'due_date',
        'delivered_qty',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sales_order_line_id' => 'integer',
            'sequence_no' => 'integer',
            'qty' => 'decimal:6',
            'due_date' => 'date',
            'delivered_qty' => 'decimal:6',
        ];
    }
}
