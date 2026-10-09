<?php

declare(strict_types=1);

namespace App\Modules\Product\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Item;
use App\Support\Audit\Auditable;
use App\Support\Calculators\ProductTypeRule;
use App\Support\Reference\Vocabulary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The make profile of an item: a made item's customer, brand, style reference, routing and
 * process type, with its specs, artworks and bills of materials hanging off it.
 *
 * Code, name, classification and lifecycle live on the {@see Item}; `code` and `name` here
 * read through to it so a document line keeps saying `$line->product->code`.
 *
 * P1 — a buyer-specific product belongs to exactly one customer and never changes hands: the
 * price, the artwork approval and the certification claim all belong to that relationship. A
 * standard product belongs to no customer and may be ordered by any.
 *
 * @property int $id
 * @property int $item_id
 * @property int|null $customer_id
 * @property int|null $brand_id
 * @property int|null $routing_id
 * @property string|null $customer_style_ref
 * @property string $product_type
 * @property bool $is_running_programme
 * @property string|null $annual_forecast_qty
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read string $code
 * @property-read string $name
 * @property-read string $status
 */
class Product extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'products';

    public const UPDATED_AT = null;

    protected $with = ['item'];

    protected $appends = ['code', 'name', 'status'];

    protected $fillable = [
        'item_id',
        'customer_id',
        'brand_id',
        'routing_id',
        'customer_style_ref',
        'product_type',
        'is_running_programme',
        'annual_forecast_qty',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'customer_id' => 'integer',
            'brand_id' => 'integer',
            'routing_id' => 'integer',
            'is_running_programme' => 'boolean',
            'annual_forecast_qty' => 'decimal:6',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return Attribute<string|null, never> */
    protected function code(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->item?->code);
    }

    /** @return Attribute<string|null, never> */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->item?->name);
    }

    /** @return Attribute<string|null, never> */
    protected function status(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->item?->status);
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

    /**
     * Products whose item is active — the only ones a new document line may name.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereHas('item', fn (Builder $item) => $item->where('status', Item::ACTIVE));
    }

    /** @param Builder<$this> $query */
    public function scopeWhereCode(Builder $query, string $code): void
    {
        $query->whereHas('item', fn (Builder $item) => $item->where('code', $code));
    }

    /**
     * Products a customer may order: their own, and every standard one.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForCustomer(Builder $query, int $customerId): void
    {
        $query->where(fn (Builder $q) => $q->where('customer_id', $customerId)->orWhereNull('customer_id'));
    }
}
