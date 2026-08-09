<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $lot_id
 * @property int|null $item_id
 * @property int|null $product_id
 * @property int $warehouse_id
 * @property int|null $bin_id
 * @property string $movement_type
 * @property string $qty
 * @property int $uom_id
 * @property string $unit_cost
 * @property string $value
 * @property string $source_type
 * @property int $source_id
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property int|null $created_by
 * @property string|null $remarks
 */
class StockLedgerEntry extends Model
{
    protected $table = 'stock_ledger';

    public $timestamps = false;

    protected $fillable = [
        'lot_id',
        'item_id',
        'product_id',
        'warehouse_id',
        'bin_id',
        'movement_type',
        'qty',
        'uom_id',
        'unit_cost',
        'value',
        'source_type',
        'source_id',
        'occurred_at',
        'created_by',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lot_id' => 'integer',
            'item_id' => 'integer',
            'product_id' => 'integer',
            'warehouse_id' => 'integer',
            'bin_id' => 'integer',
            'qty' => 'decimal:6',
            'uom_id' => 'integer',
            'unit_cost' => 'decimal:4',
            'value' => 'decimal:4',
            'source_id' => 'integer',
            'occurred_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<StockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\MorphTo<Model, $this> */
    public function source(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo('source');
    }
}
