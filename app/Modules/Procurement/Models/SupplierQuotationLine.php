<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $supplier_quotation_id
 * @property int $line_no
 * @property int $item_id
 * @property string $qty
 * @property int $uom_id
 * @property string $rate
 * @property string $amount
 */
class SupplierQuotationLine extends Model
{
    protected $table = 'supplier_quotation_lines';

    public $timestamps = false;

    protected $fillable = [
        'supplier_quotation_id',
        'line_no',
        'item_id',
        'qty',
        'uom_id',
        'rate',
        'amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_quotation_id' => 'integer',
            'line_no' => 'integer',
            'item_id' => 'integer',
            'qty' => 'decimal:6',
            'uom_id' => 'integer',
            'rate' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }
}
