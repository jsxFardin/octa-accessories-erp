<?php

declare(strict_types=1);

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $product_spec_id
 * @property string $kind
 * @property string $code
 * @property int|null $colour_index
 * @property string|null $location
 * @property \Illuminate\Support\Carbon|null $made_on
 * @property string $cost
 * @property int|null $life_impressions
 * @property int $used_impressions
 * @property string $status
 * @property \Illuminate\Support\Carbon $created_at
 */
class Tool extends Model
{
    protected $table = 'tools';

    public const UPDATED_AT = null;

    protected $fillable = [
        'product_spec_id',
        'kind',
        'code',
        'colour_index',
        'location',
        'made_on',
        'cost',
        'life_impressions',
        'used_impressions',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_spec_id' => 'integer',
            'colour_index' => 'integer',
            'made_on' => 'date',
            'cost' => 'decimal:4',
            'life_impressions' => 'integer',
            'used_impressions' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductSpec, $this> */
    public function spec(): BelongsTo
    {
        return $this->belongsTo(ProductSpec::class, 'product_spec_id');
    }

    /** BR-13 — impressions left before this tool must be remade. */
    public function remainingLifeImpressions(): float
    {
        return max(0.0, (float) $this->life_impressions - (float) $this->used_impressions);
    }

    public function canCover(float $requiredImpressions): bool
    {
        return $this->status === 'available' && $this->remainingLifeImpressions() >= $requiredImpressions;
    }
}
