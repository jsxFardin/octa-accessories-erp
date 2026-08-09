<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $lot_id
 * @property int|null $item_id
 * @property int|null $product_id
 * @property int $warehouse_id
 * @property string $lot_no
 * @property string|null $shade_code
 * @property string|null $cert_scheme
 * @property string $cert_claim_pct
 * @property string $balance_qty
 * @property \Illuminate\Support\Carbon|null $received_on
 * @property \Illuminate\Support\Carbon $refreshed_at
 */
class StockBalance extends Model
{
    protected $table = 'stock_balances';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'lot_id',
        'item_id',
        'product_id',
        'warehouse_id',
        'lot_no',
        'shade_code',
        'cert_scheme',
        'cert_claim_pct',
        'balance_qty',
        'received_on',
        'refreshed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lot_id' => 'integer',
            'item_id' => 'integer',
            'product_id' => 'integer',
            'warehouse_id' => 'integer',
            'cert_claim_pct' => 'decimal:4',
            'balance_qty' => 'decimal:6',
            'received_on' => 'date',
            'refreshed_at' => 'datetime',
        ];
    }
}
