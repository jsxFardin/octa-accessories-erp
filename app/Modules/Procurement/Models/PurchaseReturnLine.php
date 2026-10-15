<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\Inventory\Models\StockLot;
use App\Modules\MasterData\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_return_id
 * @property int $line_no
 * @property int $item_id
 * @property int|null $lot_id
 * @property string $qty
 * @property string $rate
 * @property-read PurchaseReturn $return
 * @property-read Item|null $item
 * @property-read StockLot|null $lot
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

    /** @return BelongsTo<PurchaseReturn, $this> */
    public function return(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<StockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class);
    }
}
