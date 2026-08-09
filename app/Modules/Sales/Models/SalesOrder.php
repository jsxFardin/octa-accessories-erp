<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $number
 * @property int $revision_no
 * @property int|null $quotation_id
 * @property int $customer_id
 * @property string|null $customer_po_no
 * @property \Illuminate\Support\Carbon $order_date
 * @property \Illuminate\Support\Carbon|null $delivery_date
 * @property int $currency_id
 * @property string $exchange_rate
 * @property int|null $payment_term_id
 * @property int|null $billing_address_id
 * @property int|null $delivery_address_id
 * @property int|null $merchandiser_id
 * @property int|null $factory_unit_id
 * @property string $subtotal
 * @property string $tax_amount
 * @property string $total
 * @property string $priority
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property string|null $close_reason
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class SalesOrder extends Model
{
    protected $table = 'sales_orders';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'revision_no',
        'quotation_id',
        'customer_id',
        'customer_po_no',
        'order_date',
        'delivery_date',
        'currency_id',
        'exchange_rate',
        'payment_term_id',
        'billing_address_id',
        'delivery_address_id',
        'merchandiser_id',
        'factory_unit_id',
        'subtotal',
        'tax_amount',
        'total',
        'priority',
        'status',
        'confirmed_at',
        'closed_at',
        'close_reason',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision_no' => 'integer',
            'quotation_id' => 'integer',
            'customer_id' => 'integer',
            'order_date' => 'date',
            'delivery_date' => 'date',
            'currency_id' => 'integer',
            'exchange_rate' => 'decimal:8',
            'payment_term_id' => 'integer',
            'billing_address_id' => 'integer',
            'delivery_address_id' => 'integer',
            'merchandiser_id' => 'integer',
            'factory_unit_id' => 'integer',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'confirmed_at' => 'datetime',
            'closed_at' => 'datetime',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Customer::class, 'customer_id');
    }

    /** @return HasMany<SalesOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class, 'sales_order_id')->orderBy('line_no');
    }
}
