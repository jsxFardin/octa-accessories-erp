<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $job_card_operation_id
 * @property int|null $machine_id
 * @property int|null $operator_id
 * @property int|null $shift_id
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property string $good_qty
 * @property string $waste_qty
 * @property int|null $input_lot_id
 * @property int|null $output_lot_id
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class OperationLog extends Model
{
    protected $table = 'operation_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'job_card_operation_id',
        'machine_id',
        'operator_id',
        'shift_id',
        'started_at',
        'ended_at',
        'good_qty',
        'waste_qty',
        'input_lot_id',
        'output_lot_id',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'job_card_operation_id' => 'integer',
            'machine_id' => 'integer',
            'operator_id' => 'integer',
            'shift_id' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'good_qty' => 'decimal:6',
            'waste_qty' => 'decimal:6',
            'input_lot_id' => 'integer',
            'output_lot_id' => 'integer',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }
}
