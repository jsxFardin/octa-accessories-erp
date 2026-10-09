<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Models\User;
use App\Modules\Product\Models\Product;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The item master: everything the factory buys, makes, consumes, sells or charges for.
 *
 * A made item (`make_or_buy = make`) also owns one {@see Product}, its engineering and
 * commercial profile — customer, routing, specs, artworks, bills of materials. Everything
 * else about it, from its code to its lifecycle status, is here, which is what lets a tape
 * made in family 01 sit on a zipper's bill of materials like any bought material.
 *
 * IM-1 — an item is born `draft` and becomes `active` only through the activation gate.
 *
 * @property int $id
 * @property int $item_category_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $item_type
 * @property string $make_or_buy
 * @property int|null $production_family_id
 * @property int|null $item_group_id
 * @property string|null $garment_type
 * @property string $spec_scope
 * @property int|null $customer_id
 * @property string|null $material_base
 * @property list<string> $variant_axes
 * @property int $base_uom_id
 * @property int|null $purchase_uom_id
 * @property int|null $order_uom_id
 * @property int|null $pack_pcs_per_inner
 * @property int|null $pack_inners_per_carton
 * @property int|null $default_supplier_id
 * @property int|null $default_warehouse_id
 * @property string $min_order_qty
 * @property string $order_multiple
 * @property string $reorder_level
 * @property string|null $max_stock_qty
 * @property int $safety_days
 * @property string $std_rate
 * @property string $avg_rate
 * @property string $valuation_method
 * @property string $standard_wastage_pct
 * @property string|null $density
 * @property string|null $gsm
 * @property string|null $ink_lay_gsm
 * @property string|null $shade_code
 * @property bool $is_lot_tracked
 * @property bool $is_shade_critical
 * @property bool $has_expiry
 * @property int|null $shelf_life_days
 * @property int|null $tool_owner_customer_id
 * @property int|null $tool_cavities
 * @property string|null $service_charge_basis
 * @property string|null $qc_plan_ref
 * @property string|null $inventory_account
 * @property string|null $cogs_account
 * @property array<array-key, mixed> $attributes
 * @property string $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $activated_at
 * @property int|null $activated_by
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Item extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'items';

    public const UPDATED_AT = null;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const ON_HOLD = 'on_hold';

    public const DISCONTINUED = 'discontinued';

    public const MAKE = 'make';

    public const BUY = 'buy';

    /** @var array<string, mixed> what a row carries when the writer says nothing */
    protected $attributes = [
        'item_type' => 'raw_material',
        'make_or_buy' => 'buy',
        'spec_scope' => 'standard',
        'variant_axes' => '[]',
        'valuation_method' => 'weighted_average',
        'attributes' => '{}',
        'status' => 'draft',
    ];

    protected $fillable = [
        'item_category_id',
        'code',
        'name',
        'description',
        'item_type',
        'make_or_buy',
        'production_family_id',
        'item_group_id',
        'garment_type',
        'spec_scope',
        'customer_id',
        'material_base',
        'variant_axes',
        'base_uom_id',
        'purchase_uom_id',
        'order_uom_id',
        'pack_pcs_per_inner',
        'pack_inners_per_carton',
        'default_supplier_id',
        'default_warehouse_id',
        'min_order_qty',
        'order_multiple',
        'reorder_level',
        'max_stock_qty',
        'safety_days',
        'std_rate',
        'avg_rate',
        'valuation_method',
        'standard_wastage_pct',
        'density',
        'gsm',
        'ink_lay_gsm',
        'shade_code',
        'is_lot_tracked',
        'is_shade_critical',
        'has_expiry',
        'shelf_life_days',
        'tool_owner_customer_id',
        'tool_cavities',
        'service_charge_basis',
        'qc_plan_ref',
        'inventory_account',
        'cogs_account',
        'attributes',
        'status',
        'created_by',
        'activated_at',
        'activated_by',
        'deleted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_category_id' => 'integer',
            'production_family_id' => 'integer',
            'item_group_id' => 'integer',
            'customer_id' => 'integer',
            'variant_axes' => 'array',
            'base_uom_id' => 'integer',
            'purchase_uom_id' => 'integer',
            'order_uom_id' => 'integer',
            'pack_pcs_per_inner' => 'integer',
            'pack_inners_per_carton' => 'integer',
            'default_supplier_id' => 'integer',
            'default_warehouse_id' => 'integer',
            'min_order_qty' => 'decimal:6',
            'order_multiple' => 'decimal:6',
            'reorder_level' => 'decimal:6',
            'max_stock_qty' => 'decimal:6',
            'safety_days' => 'integer',
            'std_rate' => 'decimal:4',
            'avg_rate' => 'decimal:4',
            'standard_wastage_pct' => 'decimal:4',
            'density' => 'decimal:6',
            'gsm' => 'decimal:3',
            'ink_lay_gsm' => 'decimal:3',
            'is_lot_tracked' => 'boolean',
            'is_shade_critical' => 'boolean',
            'has_expiry' => 'boolean',
            'shelf_life_days' => 'integer',
            'tool_owner_customer_id' => 'integer',
            'tool_cavities' => 'integer',
            'attributes' => 'array',
            'created_at' => 'datetime',
            'created_by' => 'integer',
            'activated_at' => 'datetime',
            'activated_by' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ItemCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    /** @return BelongsTo<ProductionFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductionFamily::class, 'production_family_id');
    }

    /** @return BelongsTo<ItemGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'item_group_id');
    }

    /** The buyer a buyer-specific bought item is nominated by. A made item's buyer is on its product. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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

    /** @return BelongsTo<Uom, $this> */
    public function orderUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'order_uom_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function toolOwner(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'tool_owner_customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /** @return HasMany<UomConversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(UomConversion::class);
    }

    /** The make profile. Present exactly when `make_or_buy` is `make`. */
    public function product(): HasOne
    {
        return $this->hasOne(Product::class, 'item_id');
    }

    public function isMade(): bool
    {
        return $this->make_or_buy === self::MAKE;
    }

    public function isFinishedGood(): bool
    {
        return $this->item_type === 'finished_good';
    }

    /**
     * Usable on a document today: active, not on hold, not discontinued.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::ACTIVE);
    }

    /** @param Builder<$this> $query */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('item_type', $type);
    }

    /** @param Builder<$this> $query */
    public function scopeMade(Builder $query): void
    {
        $query->where('make_or_buy', self::MAKE);
    }
}
