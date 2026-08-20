<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string|null $number
 * @property int|null $po_id
 * @property int $supplier_id
 * @property int $warehouse_id
 * @property \Illuminate\Support\Carbon $received_on
 * @property string|null $challan_no
 * @property string|null $invoice_no
 * @property string|null $lc_no
 * @property string|null $bill_of_entry
 * @property string $freight_amount
 * @property string $duty_amount
 * @property string $clearing_amount
 * @property string $status
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class Grn extends Model
{
    protected $table = 'grns';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'po_id',
        'supplier_id',
        'warehouse_id',
        'received_on',
        'challan_no',
        'invoice_no',
        'lc_no',
        'bill_of_entry',
        'freight_amount',
        'duty_amount',
        'clearing_amount',
        'status',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'po_id' => 'integer',
            'supplier_id' => 'integer',
            'warehouse_id' => 'integer',
            'received_on' => 'date:Y-m-d',
            'freight_amount' => 'decimal:4',
            'duty_amount' => 'decimal:4',
            'clearing_amount' => 'decimal:4',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Supplier::class, 'supplier_id');
    }
}
