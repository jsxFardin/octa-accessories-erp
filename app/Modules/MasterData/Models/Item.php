<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $item_category_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $base_uom_id
 * @property int|null $purchase_uom_id
 * @property int|null $default_supplier_id
 * @property string $min_order_qty
 * @property string $order_multiple
 * @property string $reorder_level
 * @property int $safety_days
 * @property string $std_rate
 * @property string $avg_rate
 * @property string|null $density
 * @property string|null $gsm
 * @property string|null $ink_lay_gsm
 * @property string|null $shade_code
 * @property bool $is_lot_tracked
 * @property bool $is_shade_critical
 * @property bool $has_expiry
 * @property int|null $shelf_life_days
 * @property array<array-key, mixed> $attributes
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Item extends Model
{
    use SoftDeletes;

    protected $table = 'items';

    public const UPDATED_AT = null;

    protected $fillable = [
        'item_category_id',
        'code',
        'name',
        'description',
        'base_uom_id',
        'purchase_uom_id',
        'default_supplier_id',
        'min_order_qty',
        'order_multiple',
        'reorder_level',
        'safety_days',
        'std_rate',
        'avg_rate',
        'density',
        'gsm',
        'ink_lay_gsm',
        'shade_code',
        'is_lot_tracked',
        'is_shade_critical',
        'has_expiry',
        'shelf_life_days',
        'attributes',
        'is_active',
        'deleted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_category_id' => 'integer',
            'base_uom_id' => 'integer',
            'purchase_uom_id' => 'integer',
            'default_supplier_id' => 'integer',
            'min_order_qty' => 'decimal:6',
            'order_multiple' => 'decimal:6',
            'reorder_level' => 'decimal:6',
            'safety_days' => 'integer',
            'std_rate' => 'decimal:4',
            'avg_rate' => 'decimal:4',
            'density' => 'decimal:6',
            'gsm' => 'decimal:3',
            'ink_lay_gsm' => 'decimal:3',
            'is_lot_tracked' => 'boolean',
            'is_shade_critical' => 'boolean',
            'has_expiry' => 'boolean',
            'shelf_life_days' => 'integer',
            'attributes' => 'array',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ItemCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    /** @return BelongsTo<Uom, $this> */
    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'base_uom_id');
    }

    /** @return BelongsTo<Uom, $this> */
    public function purchaseUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'purchase_uom_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }

    /** @return HasMany<UomConversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(UomConversion::class);
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
