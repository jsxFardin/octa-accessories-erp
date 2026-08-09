<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int $supplier_id
 * @property int|null $po_id
 * @property int|null $grn_id
 * @property string $bill_no
 * @property \Illuminate\Support\Carbon $bill_date
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property int $currency_id
 * @property string $exchange_rate
 * @property string $subtotal
 * @property string $tax_amount
 * @property string $total
 * @property string $paid_amount
 * @property string $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class SupplierBill extends Model
{
    protected $table = 'supplier_bills';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'supplier_id',
        'po_id',
        'grn_id',
        'bill_no',
        'bill_date',
        'due_date',
        'currency_id',
        'exchange_rate',
        'subtotal',
        'tax_amount',
        'total',
        'paid_amount',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'po_id' => 'integer',
            'grn_id' => 'integer',
            'bill_date' => 'date',
            'due_date' => 'date',
            'currency_id' => 'integer',
            'exchange_rate' => 'decimal:8',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }
}
