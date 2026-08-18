<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Procurement\Models\SupplierBill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payment_id
 * @property int $supplier_bill_id
 * @property string $amount
 */
class PaymentAllocation extends Model
{
    protected $table = 'payment_allocations';

    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'supplier_bill_id',
        'amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'supplier_bill_id' => 'integer',
            'amount' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /** @return BelongsTo<SupplierBill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }
}
