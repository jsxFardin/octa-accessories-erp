<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $price_list_id
 * @property int|null $product_id
 * @property string|null $description
 * @property string $min_qty
 * @property string $rate_per_m
 */
class PriceListLine extends Model
{
    protected $table = 'price_list_lines';

    public $timestamps = false;

    protected $fillable = [
        'price_list_id',
        'product_id',
        'description',
        'min_qty',
        'rate_per_m',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_list_id' => 'integer',
            'product_id' => 'integer',
            'min_qty' => 'decimal:6',
            'rate_per_m' => 'decimal:4',
        ];
    }
}
