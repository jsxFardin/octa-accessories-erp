<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $job_card_id
 * @property int|null $job_card_operation_id
 * @property int|null $item_id
 * @property int|null $lot_id
 * @property string $waste_type
 * @property string $qty
 * @property int|null $uom_id
 * @property string $value
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property int|null $reported_by
 * @property string|null $remarks
 */
class WasteLog extends Model
{
    protected $table = 'waste_logs';

    public $timestamps = false;

    protected $fillable = [
        'job_card_id',
        'job_card_operation_id',
        'item_id',
        'lot_id',
        'waste_type',
        'qty',
        'uom_id',
        'value',
        'occurred_at',
        'reported_by',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'job_card_id' => 'integer',
            'job_card_operation_id' => 'integer',
            'item_id' => 'integer',
            'lot_id' => 'integer',
            'qty' => 'decimal:6',
            'uom_id' => 'integer',
            'value' => 'decimal:4',
            'occurred_at' => 'datetime',
            'reported_by' => 'integer',
        ];
    }
}
