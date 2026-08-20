<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\User;
use App\Modules\MasterData\Models\FactoryUnit;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\Bom;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use App\Modules\Product\Models\Routing;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A production order for a quantity of one product, bound to a routing and — the point of
 * Gate 1 — to an **approved** artwork version.
 *
 * `artwork_version_id` is NOT NULL. There is no draft job card without one, no nullable
 * column to forget to check, and no code path that releases production against an unapproved
 * design (01-domain-model §4).
 *
 * The consumption plan is snapshotted onto the card (`gross_metres`, `ends`,
 * `labels_per_metre`) at planning: a spec revised mid-run must not change what the floor is
 * producing to.
 *
 * @property int $id
 * @property string|null $number
 * @property int $factory_unit_id
 * @property int|null $sales_order_line_id
 * @property int|null $sample_request_line_id
 * @property int|null $production_plan_line_id
 * @property int $product_id
 * @property int $product_spec_id
 * @property int $artwork_version_id
 * @property int|null $bom_id
 * @property int $routing_id
 * @property string|null $colourway
 * @property string $planned_qty
 * @property string $produced_qty
 * @property string $good_qty
 * @property string $waste_qty
 * @property string $overrun_tolerance_pct
 * @property \Illuminate\Support\Carbon|null $planned_start
 * @property \Illuminate\Support\Carbon|null $planned_finish
 * @property \Illuminate\Support\Carbon|null $actual_start
 * @property \Illuminate\Support\Carbon|null $actual_finish
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property int $priority
 * @property string|null $gross_metres
 * @property int|null $ends
 * @property string|null $labels_per_metre
 * @property string $status
 * @property string|null $hold_reason
 * @property string|null $material_waiver_reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $closed_at
 */
class JobCard extends Model
{
    use Auditable;

    public const DRAFT = 'draft';

    public const PLANNED = 'planned';

    public const MATERIAL_PENDING = 'material_pending';

    public const RELEASED = 'released';

    public const IN_PRODUCTION = 'in_production';

    public const ON_HOLD = 'on_hold';

    public const QC_PENDING = 'qc_pending';

    public const COMPLETED = 'completed';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    protected $table = 'job_cards';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'factory_unit_id',
        'sales_order_line_id',
        'sample_request_line_id',
        'production_plan_line_id',
        'product_id',
        'product_spec_id',
        'artwork_version_id',
        'bom_id',
        'routing_id',
        'colourway',
        'planned_qty',
        'produced_qty',
        'good_qty',
        'waste_qty',
        'overrun_tolerance_pct',
        'planned_start',
        'planned_finish',
        'actual_start',
        'actual_finish',
        'due_date',
        'priority',
        'gross_metres',
        'ends',
        'labels_per_metre',
        'status',
        'hold_reason',
        'material_waiver_reason',
        'created_by',
        'closed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'factory_unit_id' => 'integer',
            'sales_order_line_id' => 'integer',
            'sample_request_line_id' => 'integer',
            'production_plan_line_id' => 'integer',
            'product_id' => 'integer',
            'product_spec_id' => 'integer',
            'artwork_version_id' => 'integer',
            'bom_id' => 'integer',
            'routing_id' => 'integer',
            'planned_qty' => 'decimal:6',
            'produced_qty' => 'decimal:6',
            'good_qty' => 'decimal:6',
            'waste_qty' => 'decimal:6',
            'overrun_tolerance_pct' => 'decimal:4',
            'planned_start' => 'datetime',
            'planned_finish' => 'datetime',
            'actual_start' => 'datetime',
            'actual_finish' => 'datetime',
            'due_date' => 'date:Y-m-d',
            'priority' => 'integer',
            'gross_metres' => 'decimal:6',
            'ends' => 'integer',
            'labels_per_metre' => 'decimal:6',
            'created_at' => 'datetime',
            'created_by' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FactoryUnit, $this> */
    public function factoryUnit(): BelongsTo
    {
        return $this->belongsTo(FactoryUnit::class);
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

    /** @return BelongsTo<ArtworkVersion, $this> */
    public function artworkVersion(): BelongsTo
    {
        return $this->belongsTo(ArtworkVersion::class);
    }

    /** @return BelongsTo<Bom, $this> */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    /** @return BelongsTo<Routing, $this> */
    public function routing(): BelongsTo
    {
        return $this->belongsTo(Routing::class);
    }

    /** @return BelongsTo<SalesOrderLine, $this> */
    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * J2 — operations execute in `sequence_no` order.
     *
     * @return HasMany<JobCardOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(JobCardOperation::class)->orderBy('sequence_no');
    }

    /** @return HasMany<MaterialIssue, $this> */
    public function materialIssues(): HasMany
    {
        return $this->hasMany(MaterialIssue::class);
    }

    /** @return HasMany<WasteLog, $this> */
    public function wasteLogs(): HasMany
    {
        return $this->hasMany(WasteLog::class);
    }

    /** @return HasMany<\App\Modules\Quality\Models\Ncr, $this> */
    public function ncrs(): HasMany
    {
        return $this->hasMany(\App\Modules\Quality\Models\Ncr::class, 'job_card_id');
    }

    public function reference(): string
    {
        return $this->number ?? '(unnumbered)';
    }

    /**
     * J5 — cumulative produced quantity may not exceed the planned quantity plus its overrun
     * tolerance. Overproducing a label costs material and cannot be sold to anyone else: the
     * artwork names one brand.
     */
    public function overrunCeiling(): float
    {
        return (float) $this->planned_qty * (1 + (float) $this->overrun_tolerance_pct / 100);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::CLOSED, self::CANCELLED], true);
    }

    /** @param Builder<$this> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [self::CLOSED, self::CANCELLED]);
    }

    /** @param Builder<$this> $query */
    public function scopeOnFloor(Builder $query): void
    {
        $query->whereIn('status', [self::RELEASED, self::IN_PRODUCTION, self::ON_HOLD]);
    }
}
