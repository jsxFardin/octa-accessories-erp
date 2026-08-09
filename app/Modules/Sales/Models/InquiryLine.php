<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $inquiry_id
 * @property int $line_no
 * @property int|null $product_id
 * @property string $description
 * @property string|null $product_type
 * @property string $qty
 * @property string|null $target_rate_per_m
 * @property string|null $notes
 */
class InquiryLine extends Model
{
    protected $table = 'inquiry_lines';

    public $timestamps = false;

    protected $fillable = [
        'inquiry_id',
        'line_no',
        'product_id',
        'description',
        'product_type',
        'qty',
        'target_rate_per_m',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'inquiry_id' => 'integer',
            'line_no' => 'integer',
            'product_id' => 'integer',
            'qty' => 'decimal:6',
            'target_rate_per_m' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<\App\Modules\Product\Models\Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Models\Product::class);
    }
}
