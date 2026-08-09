<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $stock_adjustment_id
 * @property int $line_no
 * @property int $lot_id
 * @property string $qty_delta
 * @property string|null $remarks
 */
class StockAdjustmentLine extends Model
{
    protected $table = 'stock_adjustment_lines';

    public $timestamps = false;

    protected $fillable = [
        'stock_adjustment_id',
        'line_no',
        'lot_id',
        'qty_delta',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stock_adjustment_id' => 'integer',
            'line_no' => 'integer',
            'lot_id' => 'integer',
            'qty_delta' => 'decimal:6',
        ];
    }
}
