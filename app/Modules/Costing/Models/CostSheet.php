<?php

declare(strict_types=1);

namespace App\Modules\Costing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $quotation_line_id
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
    protected $table = 'cost_sheets';

    public const UPDATED_AT = null;

    protected $fillable = [
        'quotation_line_id',
        'product_id',
        'product_spec_id',
        'basis_qty',
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
        'is_locked',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quotation_line_id' => 'integer',
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
}
