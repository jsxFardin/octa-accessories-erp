<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int $supplier_id
 * @property \Illuminate\Support\Carbon $payment_date
 * @property string $method
 * @property string|null $reference_no
 * @property int $currency_id
 * @property string $exchange_rate
 * @property string $amount
 * @property string $allocated_amount
 * @property string $status
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class Payment extends Model
{
    protected $table = 'payments';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'supplier_id',
        'payment_date',
        'method',
        'reference_no',
        'currency_id',
        'exchange_rate',
        'amount',
        'allocated_amount',
        'status',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'payment_date' => 'date',
            'currency_id' => 'integer',
            'exchange_rate' => 'decimal:8',
            'amount' => 'decimal:4',
            'allocated_amount' => 'decimal:4',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }
}
