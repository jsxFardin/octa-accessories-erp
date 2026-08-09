<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $po_id
 * @property int $line_no
 * @property int $item_id
 * @property int|null $pr_line_id
 * @property string|null $description
 * @property string $qty
 * @property int $uom_id
 * @property string $rate
 * @property int|null $tax_id
 * @property string $amount
 * @property string $received_qty
 * @property string $billed_qty
 * @property \Illuminate\Support\Carbon|null $expected_date
 * @property string|null $cert_claim
 */
class PurchaseOrderLine extends Model
{
    protected $table = 'purchase_order_lines';

    public $timestamps = false;

    protected $fillable = [
        'po_id',
        'line_no',
        'item_id',
        'pr_line_id',
        'description',
        'qty',
        'uom_id',
        'rate',
        'tax_id',
        'amount',
        'received_qty',
        'billed_qty',
        'expected_date',
        'cert_claim',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'po_id' => 'integer',
            'line_no' => 'integer',
            'item_id' => 'integer',
            'pr_line_id' => 'integer',
            'qty' => 'decimal:6',
            'uom_id' => 'integer',
            'rate' => 'decimal:4',
            'tax_id' => 'integer',
            'amount' => 'decimal:4',
            'received_qty' => 'decimal:6',
            'billed_qty' => 'decimal:6',
            'expected_date' => 'date',
        ];
    }
}
