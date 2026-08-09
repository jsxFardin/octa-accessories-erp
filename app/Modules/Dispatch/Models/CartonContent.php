<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $carton_id
 * @property int|null $sales_order_line_id
 * @property int $product_id
 * @property int|null $lot_id
 * @property string|null $colourway
 * @property string $qty
 * @property int|null $bundles
 */
class CartonContent extends Model
{
    protected $table = 'carton_contents';

    public $timestamps = false;

    protected $fillable = [
        'carton_id',
        'sales_order_line_id',
        'product_id',
        'lot_id',
        'colourway',
        'qty',
        'bundles',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'carton_id' => 'integer',
            'sales_order_line_id' => 'integer',
            'product_id' => 'integer',
            'lot_id' => 'integer',
            'qty' => 'decimal:6',
            'bundles' => 'integer',
        ];
    }
}
