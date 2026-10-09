<?php

declare(strict_types=1);

namespace App\Modules\Costing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cost sheet: one costing of one product at one quantity, at one of the three stages
 * (spec §4). A marketing sheet is the quotation's; the pre-production sheet is the order's
 * benchmark, locked at confirmation; the post-production sheet is the job's actuals.
 *
 * @property int $id
 * @property string|null $number
 * @property int $revision_no
 * @property string $stage
 * @property string $status
 * @property int|null $quotation_line_id
 * @property int|null $sales_order_line_id
 * @property int|null $job_card_id
 * @property string $costing_per
 * @property int|null $currency_id
 * @property string $exchange_rate
 * @property string|null $incoterm
 * @property string|null $revenue_per_unit
 * @property string|null $variance_reason
 * @property \Illuminate\Support\Carbon|null $locked_at
 * @property int|null $submitted_by
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $product_id
 * @property int|null $product_spec_id
 * @property string $basis_qty
 * @property string|null $gross_metres
 * @property string $total_wastage_pct
 * @property string $overhead_pct
 * @property string $admin_pct
 * @property string $margin_pct
 * @property string $material_cost
 * @property string $tooling_cost
 * @property string $machine_cost
 * @property string $labour_cost
 * @property string $energy_cost
 * @property string $packing_cost
 * @property string $other_cost
 * @property string $overhead_amount
 * @property string $total_cost
 * @property string $unit_cost
 * @property string $rate_per_m
 * @property bool $is_locked
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class CostSheet extends Model
{
    use \App\Support\Audit\Auditable;

    protected $table = 'cost_sheets';

    public const UPDATED_AT = null;

    public const MARKETING = 'marketing';

    public const PRE_PRODUCTION = 'pre_production';

    public const POST_PRODUCTION = 'post_production';

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'number',
        'revision_no',
        'stage',
        'status',
        'quotation_line_id',
        'sales_order_line_id',
        'job_card_id',
        'product_id',
        'product_spec_id',
        'basis_qty',
        'costing_per',
        'currency_id',
        'exchange_rate',
        'incoterm',
        'gross_metres',
        'total_wastage_pct',
        'overhead_pct',
        'admin_pct',
        'margin_pct',
        'material_cost',
        'tooling_cost',
        'machine_cost',
        'labour_cost',
        'energy_cost',
        'packing_cost',
        'other_cost',
        'overhead_amount',
        'total_cost',
        'unit_cost',
        'rate_per_m',
        'revenue_per_unit',
        'variance_reason',
        'is_locked',
        'locked_at',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quotation_line_id' => 'integer',
            'sales_order_line_id' => 'integer',
            'job_card_id' => 'integer',
            'revenue_per_unit' => 'decimal:6',
            'locked_at' => 'datetime',
            'revision_no' => 'integer',
            'costing_per' => 'decimal:6',
            'currency_id' => 'integer',
            'exchange_rate' => 'decimal:6',
            'submitted_by' => 'integer',
            'submitted_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'product_id' => 'integer',
            'product_spec_id' => 'integer',
            'basis_qty' => 'decimal:6',
            'gross_metres' => 'decimal:6',
            'total_wastage_pct' => 'decimal:4',
            'overhead_pct' => 'decimal:4',
            'admin_pct' => 'decimal:4',
            'margin_pct' => 'decimal:4',
            'material_cost' => 'decimal:4',
            'tooling_cost' => 'decimal:4',
            'machine_cost' => 'decimal:4',
            'labour_cost' => 'decimal:4',
            'energy_cost' => 'decimal:4',
            'packing_cost' => 'decimal:4',
            'other_cost' => 'decimal:4',
            'overhead_amount' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'rate_per_m' => 'decimal:4',
            'is_locked' => 'boolean',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return HasMany<CostSheetLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CostSheetLine::class, 'cost_sheet_id')->orderBy('sequence_no');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Models\Product::class);
    }

    public function quotationLine(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Sales\Models\QuotationLine::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Sales\Models\SalesOrderLine::class);
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Manufacturing\Models\JobCard::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    /** The margin the price leaves, against the stored price per unit; null without a price. */
    public function marginPct(): ?float
    {
        $revenue = $this->revenue_per_unit === null ? null : (float) $this->revenue_per_unit;

        return $revenue === null || $revenue <= 0 ? null : round(($revenue - (float) $this->unit_cost) / $revenue * 100, 4);
    }
}
