<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $delivery_challan_id
 * @property int $line_no
 * @property int|null $sales_order_line_id
 * @property int $product_id
 * @property int|null $lot_id
 * @property string $qty
 * @property int|null $cartons
 */
class DeliveryChallanLine extends Model
{
    protected $table = 'delivery_challan_lines';

    public $timestamps = false;

    protected $fillable = [
        'delivery_challan_id',
        'line_no',
        'sales_order_line_id',
        'product_id',
        'lot_id',
        'qty',
        'cartons',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivery_challan_id' => 'integer',
            'line_no' => 'integer',
            'sales_order_line_id' => 'integer',
            'product_id' => 'integer',
            'lot_id' => 'integer',
            'qty' => 'decimal:6',
            'cartons' => 'integer',
        ];
    }
}
