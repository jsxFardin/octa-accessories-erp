<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $line_no
 * @property int $lot_id
 * @property string $qty
 * @property string $received_qty
 */
class StockTransferLine extends Model
{
    protected $table = 'stock_transfer_lines';

    public $timestamps = false;

    protected $fillable = [
        'stock_transfer_id',
        'line_no',
        'lot_id',
        'qty',
        'received_qty',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stock_transfer_id' => 'integer',
            'line_no' => 'integer',
            'lot_id' => 'integer',
            'qty' => 'decimal:6',
            'received_qty' => 'decimal:6',
        ];
    }
}
