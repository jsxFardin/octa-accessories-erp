<?php

declare(strict_types=1);

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $product_type
 * @property string|null $max_lot_size
 * @property bool $is_default
 * @property bool $is_active
 */
class Routing extends Model
{
    protected $table = 'routings';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'product_type',
        'max_lot_size',
        'is_default',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_lot_size' => 'decimal:6',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<RoutingOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(RoutingOperation::class)->orderBy('sequence_no');
    }

    /**
     * BR-8 — the additive wastage total for this routing, counting only the operations that
     * consume the web.
     */
    public function totalWastagePct(): float
    {
        return (float) $this->operations()->where('consumes_web', true)->sum('wastage_pct');
    }

    /** @return list<\App\Support\Calculators\RoutingStep> */
    public function toCalculatorSteps(): array
    {
        return $this->operations->map(fn (RoutingOperation $op): \App\Support\Calculators\RoutingStep => $op->toCalculatorStep())->all();
    }
}
