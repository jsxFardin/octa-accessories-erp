<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $material_issue_id
 * @property int $line_no
 * @property int $item_id
 * @property int $lot_id
 * @property int $uom_id
 * @property string $qty
 * @property string $unit_cost
 * @property string|null $fifo_override_reason
 */
class MaterialIssueLine extends Model
{
    protected $table = 'material_issue_lines';

    public $timestamps = false;

    protected $fillable = [
        'material_issue_id',
        'line_no',
        'item_id',
        'lot_id',
        'uom_id',
        'qty',
        'unit_cost',
        'fifo_override_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'material_issue_id' => 'integer',
            'line_no' => 'integer',
            'item_id' => 'integer',
            'lot_id' => 'integer',
            'uom_id' => 'integer',
            'qty' => 'decimal:6',
            'unit_cost' => 'decimal:4',
        ];
    }
}
