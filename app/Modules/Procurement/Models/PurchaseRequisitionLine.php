<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $pr_id
 * @property int $line_no
 * @property int $item_id
 * @property int $uom_id
 * @property string $qty
 * @property string $ordered_qty
 * @property \Illuminate\Support\Carbon|null $required_by
 * @property int|null $job_card_id
 * @property string|null $remarks
 */
class PurchaseRequisitionLine extends Model
{
    protected $table = 'purchase_requisition_lines';

    public $timestamps = false;

    protected $fillable = [
        'pr_id',
        'line_no',
        'item_id',
        'uom_id',
        'qty',
        'ordered_qty',
        'required_by',
        'job_card_id',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pr_id' => 'integer',
            'line_no' => 'integer',
            'item_id' => 'integer',
            'uom_id' => 'integer',
            'qty' => 'decimal:6',
            'ordered_qty' => 'decimal:6',
            'required_by' => 'date',
            'job_card_id' => 'integer',
        ];
    }
}
