<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $mrp_run_id
 * @property int $item_id
 * @property int|null $warehouse_id
 * @property \Illuminate\Support\Carbon $need_date
 * @property string $gross_req_qty
 * @property string $on_hand_qty
 * @property string $reserved_qty
 * @property string $on_order_qty
 * @property string $net_req_qty
 * @property string $suggested_po_qty
 * @property \Illuminate\Support\Carbon|null $po_place_by
 * @property bool $is_shortage
 * @property int|null $pr_line_id
 */
class MaterialRequirement extends Model
{
    protected $table = 'material_requirements';

    public $timestamps = false;

    protected $fillable = [
        'mrp_run_id',
        'item_id',
        'warehouse_id',
        'need_date',
        'gross_req_qty',
        'on_hand_qty',
        'reserved_qty',
        'on_order_qty',
        'net_req_qty',
        'suggested_po_qty',
        'po_place_by',
        'is_shortage',
        'pr_line_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mrp_run_id' => 'integer',
            'item_id' => 'integer',
            'warehouse_id' => 'integer',
            'need_date' => 'date',
            'gross_req_qty' => 'decimal:6',
            'on_hand_qty' => 'decimal:6',
            'reserved_qty' => 'decimal:6',
            'on_order_qty' => 'decimal:6',
            'net_req_qty' => 'decimal:6',
            'suggested_po_qty' => 'decimal:6',
            'po_place_by' => 'date',
            'is_shortage' => 'boolean',
            'pr_line_id' => 'integer',
        ];
    }
}
