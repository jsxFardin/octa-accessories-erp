<?php

declare(strict_types=1);

namespace App\Modules\Product\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Customer;
use App\Support\Audit\Auditable;
use App\Support\Calculators\ProductTypeRule;
use App\Support\Reference\Vocabulary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A saleable finished label or tag, defined for one customer and style.
 *
 * P1 — a Product belongs to exactly one Customer. Two customers never share a Product row
 * even for an identical label, because the price, the artwork approval and the certification
 * claim all belong to a commercial relationship, not to a shape.
 *
 * @property int $id
 * @property int $customer_id
 * @property int|null $brand_id
 * @property int|null $routing_id
 * @property string $code
 * @property string|null $customer_style_ref
 * @property string $name
 * @property string $product_type
 * @property bool $is_running_programme
 * @property string|null $annual_forecast_qty
 * @property string $status
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Product extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'products';

    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'brand_id',
        'routing_id',
        'code',
        'customer_style_ref',
        'name',
        'product_type',
        'is_running_programme',
        'annual_forecast_qty',
        'status',
        'is_active',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'brand_id' => 'integer',
            'routing_id' => 'integer',
            'is_running_programme' => 'boolean',
            'annual_forecast_qty' => 'decimal:6',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Routing, $this> */
    public function routing(): BelongsTo
    {
        return $this->belongsTo(Routing::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ProductSpec, $this> */
    public function specs(): HasMany
    {
        return $this->hasMany(ProductSpec::class)->orderByDesc('version_no');
    }

    /**
     * P2 — exactly one spec is `current` at a time, enforced by a generated NULL-able key
     * column plus a unique index, not by application discipline (02-database-schema §5.1).
     *
     * @return HasOne<ProductSpec, $this>
     */
    public function currentSpec(): HasOne
    {
        return $this->hasOne(ProductSpec::class)->where('status', ProductSpec::CURRENT);
    }

    /** @return HasMany<Artwork, $this> */
    public function artworks(): HasMany
    {
        return $this->hasMany(Artwork::class);
    }

    /** @return HasMany<Bom, $this> */
    public function boms(): HasMany
    {
        return $this->hasMany(Bom::class);
    }

    /** @return HasOne<Bom, $this> */
    public function activeBom(): HasOne
    {
        return $this->hasOne(Bom::class)->where('status', Bom::ACTIVE);
    }

    /** BR-9 · BR-10 · BR-11 · BR-13 — the costing behaviour configured for this type. */
    public function type(): ProductTypeRule
    {
        return Vocabulary::productType($this->product_type);
    }

    /**
     * S3 / Gate 1 — a line cannot be confirmed unless its product has a current spec and an
     * approved artwork version. Rendered on the sales order readiness panel.
     *
     * @return array{spec: bool, artwork: bool, bom: bool, ready: bool}
     */
    public function readiness(): array
    {
        $spec = $this->currentSpec()->exists();
        $artwork = ArtworkVersion::query()
            ->whereIn('artwork_id', $this->artworks()->select('id'))
            ->where('status', ArtworkVersion::APPROVED)
            ->exists();
        $bom = $this->activeBom()->exists();

        return [
            'spec' => $spec,
            'artwork' => $artwork,
            'bom' => $bom,
            'ready' => $spec && $artwork,
        ];
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<$this> $query */
    public function scopeForCustomer(Builder $query, int $customerId): void
    {
        $query->where('customer_id', $customerId);
    }
}
