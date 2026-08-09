<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sales_order_id
 * @property int $line_no
 * @property int $product_id
 * @property int $product_spec_id
 * @property int|null $artwork_version_id
 * @property string|null $description
 * @property string $ordered_qty
 * @property string $produced_qty
 * @property string $delivered_qty
 * @property string $invoiced_qty
 * @property string $rate_per_m
 * @property string $tooling_charge
 * @property int|null $tax_id
 * @property string $line_total
 * @property string $over_tolerance_pct
 * @property string $under_tolerance_pct
 * @property \Illuminate\Support\Carbon|null $promised_date
 * @property string $status
 */
class SalesOrderLine extends Model
{
    protected $table = 'sales_order_lines';

    public $timestamps = false;

    protected $fillable = [
        'sales_order_id',
        'line_no',
        'product_id',
        'product_spec_id',
        'artwork_version_id',
        'description',
        'ordered_qty',
        'produced_qty',
        'delivered_qty',
        'invoiced_qty',
        'rate_per_m',
        'tooling_charge',
        'tax_id',
        'line_total',
        'over_tolerance_pct',
        'under_tolerance_pct',
        'promised_date',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sales_order_id' => 'integer',
            'line_no' => 'integer',
            'product_id' => 'integer',
            'product_spec_id' => 'integer',
            'artwork_version_id' => 'integer',
            'ordered_qty' => 'decimal:6',
            'produced_qty' => 'decimal:6',
            'delivered_qty' => 'decimal:6',
            'invoiced_qty' => 'decimal:6',
            'rate_per_m' => 'decimal:4',
            'tooling_charge' => 'decimal:4',
            'tax_id' => 'integer',
            'line_total' => 'decimal:4',
            'over_tolerance_pct' => 'decimal:4',
            'under_tolerance_pct' => 'decimal:4',
            'promised_date' => 'date',
        ];
    }

    /** @return BelongsTo<\App\Modules\Product\Models\Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Models\Product::class);
    }

    /** @return BelongsTo<\App\Modules\Product\Models\ProductSpec, $this> */
    public function spec(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Models\ProductSpec::class, 'product_spec_id');
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}
