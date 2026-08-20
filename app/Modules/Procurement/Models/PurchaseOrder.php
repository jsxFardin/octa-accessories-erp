<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $number
 * @property int $revision_no
 * @property int $supplier_id
 * @property int $factory_unit_id
 * @property \Illuminate\Support\Carbon $order_date
 * @property \Illuminate\Support\Carbon|null $expected_date
 * @property int $currency_id
 * @property string $exchange_rate
 * @property int|null $payment_term_id
 * @property string|null $incoterm
 * @property string $subtotal
 * @property string $tax_amount
 * @property string $freight_amount
 * @property string $total
 * @property string $status
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'revision_no',
        'supplier_id',
        'factory_unit_id',
        'order_date',
        'expected_date',
        'currency_id',
        'exchange_rate',
        'payment_term_id',
        'incoterm',
        'subtotal',
        'tax_amount',
        'freight_amount',
        'total',
        'status',
        'approved_by',
        'approved_at',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision_no' => 'integer',
            'supplier_id' => 'integer',
            'factory_unit_id' => 'integer',
            'order_date' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
            'currency_id' => 'integer',
            'exchange_rate' => 'decimal:8',
            'payment_term_id' => 'integer',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'freight_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Supplier::class, 'supplier_id');
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'po_id')->orderBy('line_no');
    }
}
