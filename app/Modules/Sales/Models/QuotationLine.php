<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $quotation_id
 * @property int $line_no
 * @property int|null $product_id
 * @property int|null $product_spec_id
 * @property string $description
 * @property string $qty
 * @property string $rate_per_m
 * @property string $tooling_charge
 * @property int|null $tax_id
 * @property string $line_total
 * @property int|null $lead_time_days
 */
class QuotationLine extends Model
{
    protected $table = 'quotation_lines';

    public $timestamps = false;

    protected $fillable = [
        'quotation_id',
        'line_no',
        'product_id',
        'product_spec_id',
        'description',
        'qty',
        'rate_per_m',
        'tooling_charge',
        'tax_id',
        'line_total',
        'lead_time_days',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quotation_id' => 'integer',
            'line_no' => 'integer',
            'product_id' => 'integer',
            'product_spec_id' => 'integer',
            'qty' => 'decimal:6',
            'rate_per_m' => 'decimal:4',
            'tooling_charge' => 'decimal:4',
            'tax_id' => 'integer',
            'line_total' => 'decimal:4',
            'lead_time_days' => 'integer',
        ];
    }

    /** @return BelongsTo<\App\Modules\Product\Models\Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Models\Product::class);
    }

    /** @return BelongsTo<Quotation, $this> */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
