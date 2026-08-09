<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $lot_id
 * @property int $item_id
 * @property int $warehouse_id
 * @property int|null $job_card_id
 * @property string $qty
 * @property \Illuminate\Support\Carbon $reserved_on
 * @property \Illuminate\Support\Carbon|null $released_on
 * @property string $status
 */
class StockReservation extends Model
{
    protected $table = 'stock_reservations';

    public $timestamps = false;

    protected $fillable = [
        'lot_id',
        'item_id',
        'warehouse_id',
        'job_card_id',
        'qty',
        'reserved_on',
        'released_on',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lot_id' => 'integer',
            'item_id' => 'integer',
            'warehouse_id' => 'integer',
            'job_card_id' => 'integer',
            'qty' => 'decimal:6',
            'reserved_on' => 'datetime',
            'released_on' => 'datetime',
        ];
    }
}
