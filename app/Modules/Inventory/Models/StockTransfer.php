<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int $from_warehouse_id
 * @property int $to_warehouse_id
 * @property \Illuminate\Support\Carbon $transfer_date
 * @property string $status
 * @property string|null $remarks
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class StockTransfer extends Model
{
    protected $table = 'stock_transfers';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'from_warehouse_id',
        'to_warehouse_id',
        'transfer_date',
        'status',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_warehouse_id' => 'integer',
            'to_warehouse_id' => 'integer',
            'transfer_date' => 'date',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
