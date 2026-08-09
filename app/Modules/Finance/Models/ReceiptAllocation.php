<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $receipt_id
 * @property int $sales_invoice_id
 * @property string $amount
 */
class ReceiptAllocation extends Model
{
    protected $table = 'receipt_allocations';

    public $timestamps = false;

    protected $fillable = [
        'receipt_id',
        'sales_invoice_id',
        'amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'receipt_id' => 'integer',
            'sales_invoice_id' => 'integer',
            'amount' => 'decimal:4',
        ];
    }
}
