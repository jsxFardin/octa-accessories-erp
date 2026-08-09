<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int $warehouse_id
 * @property \Illuminate\Support\Carbon $adjusted_on
 * @property string $reason
 * @property string $status
 * @property int|null $approved_by
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class StockAdjustment extends Model
{
    protected $table = 'stock_adjustments';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'warehouse_id',
        'adjusted_on',
        'reason',
        'status',
        'approved_by',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'warehouse_id' => 'integer',
            'adjusted_on' => 'date',
            'approved_by' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
