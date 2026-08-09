<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $purchase_return_id
 * @property int $line_no
 * @property int $item_id
 * @property int|null $lot_id
 * @property string $qty
 * @property string $rate
 */
class PurchaseReturnLine extends Model
{
    protected $table = 'purchase_return_lines';

    public $timestamps = false;

    protected $fillable = [
        'purchase_return_id',
        'line_no',
        'item_id',
        'lot_id',
        'qty',
        'rate',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchase_return_id' => 'integer',
            'line_no' => 'integer',
            'item_id' => 'integer',
            'lot_id' => 'integer',
            'qty' => 'decimal:6',
            'rate' => 'decimal:4',
        ];
    }
}
