<?php

declare(strict_types=1);

namespace App\Modules\Product\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bill of materials, expressed per `base_qty` finished pieces — 1000 by default, because
 * everything in this business is quoted and consumed per 1000 (BR-1).
 *
 * PD-3 — exactly one BOM per product is `active`, enforced by the `active_key` generated
 * column (02-database-schema §5.1).
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_spec_id
 * @property int $version_no
 * @property string $status
 * @property string $base_qty
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 * @property int|null $active_key
 */
class Bom extends Model
{
    use Auditable;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const SUPERSEDED = 'superseded';

    protected $table = 'boms';

    public const UPDATED_AT = null;

    // `active_key` is a STORED generated column: MySQL writes it, the application never does.

    protected $fillable = [
        'product_id',
        'product_spec_id',
        'version_no',
        'status',
        'base_qty',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'product_spec_id' => 'integer',
            'version_no' => 'integer',
            'base_qty' => 'decimal:6',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductSpec, $this> */
    public function spec(): BelongsTo
    {
        return $this->belongsTo(ProductSpec::class, 'product_spec_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<BomLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BomLine::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * Scale a per-base quantity to an order quantity.
     */
    public function scaleTo(float $qtyPerBase, float $orderQty): float
    {
        return round($qtyPerBase * ($orderQty / (float) $this->base_qty), 6);
    }
}
