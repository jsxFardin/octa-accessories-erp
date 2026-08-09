<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $supplier_bill_id
 * @property int $line_no
 * @property int|null $item_id
 * @property string|null $description
 * @property string $qty
 * @property string $rate
 * @property int|null $tax_id
 * @property string $amount
 */
class SupplierBillLine extends Model
{
    protected $table = 'supplier_bill_lines';

    public $timestamps = false;

    protected $fillable = [
        'supplier_bill_id',
        'line_no',
        'item_id',
        'description',
        'qty',
        'rate',
        'tax_id',
        'amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_bill_id' => 'integer',
            'line_no' => 'integer',
            'item_id' => 'integer',
            'qty' => 'decimal:6',
            'rate' => 'decimal:4',
            'tax_id' => 'integer',
            'amount' => 'decimal:4',
        ];
    }
}
