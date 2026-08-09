<?php

declare(strict_types=1);

namespace App\Modules\Costing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $cost_sheet_id
 * @property int $sequence_no
 * @property string $cost_type
 * @property int|null $item_id
 * @property int|null $machine_group_id
 * @property string $description
 * @property string|null $basis_uom
 * @property string $qty
 * @property string $rate
 * @property string $amount
 * @property string|null $formula_ref
 */
class CostSheetLine extends Model
{
    protected $table = 'cost_sheet_lines';

    public $timestamps = false;

    protected $fillable = [
        'cost_sheet_id',
        'sequence_no',
        'cost_type',
        'item_id',
        'machine_group_id',
        'description',
        'basis_uom',
        'qty',
        'rate',
        'amount',
        'formula_ref',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cost_sheet_id' => 'integer',
            'sequence_no' => 'integer',
            'item_id' => 'integer',
            'machine_group_id' => 'integer',
            'qty' => 'decimal:6',
            'rate' => 'decimal:6',
            'amount' => 'decimal:4',
        ];
    }
}
