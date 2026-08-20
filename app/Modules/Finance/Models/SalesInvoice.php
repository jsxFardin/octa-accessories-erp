<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string|null $number
 * @property int $customer_id
 * @property int|null $sales_order_id
 * @property int|null $delivery_challan_id
 * @property \Illuminate\Support\Carbon $invoice_date
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property int $currency_id
 * @property string $exchange_rate
 * @property string $subtotal
 * @property string $tax_amount
 * @property string $total
 * @property string $received_amount
 * @property string $status
 * @property string|null $lc_no
 * @property string|null $mushak_no
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class SalesInvoice extends Model
{
    protected $table = 'sales_invoices';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'customer_id',
        'sales_order_id',
        'delivery_challan_id',
        'invoice_date',
        'due_date',
        'currency_id',
        'exchange_rate',
        'subtotal',
        'tax_amount',
        'total',
        'received_amount',
        'status',
        'lc_no',
        'mushak_no',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'sales_order_id' => 'integer',
            'delivery_challan_id' => 'integer',
            'invoice_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'currency_id' => 'integer',
            'exchange_rate' => 'decimal:8',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'received_amount' => 'decimal:4',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Customer::class, 'customer_id');
    }
}
