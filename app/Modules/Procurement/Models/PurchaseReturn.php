<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int|null $grn_id
 * @property int $supplier_id
 * @property \Illuminate\Support\Carbon $returned_on
 * @property string $reason
 * @property string $status
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class PurchaseReturn extends Model
{
    protected $table = 'purchase_returns';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'grn_id',
        'supplier_id',
        'returned_on',
        'reason',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'grn_id' => 'integer',
            'supplier_id' => 'integer',
            'returned_on' => 'date:Y-m-d',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
