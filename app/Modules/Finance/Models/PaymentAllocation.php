<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

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
}
