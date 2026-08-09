<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int|null $sales_order_id
 * @property int $customer_id
 * @property int|null $delivery_address_id
 * @property \Illuminate\Support\Carbon $packed_on
 * @property int $total_cartons
 * @property string $total_qty
 * @property string|null $gross_weight_kg
 * @property string|null $net_weight_kg
 * @property string $status
 * @property string|null $cert_claim_scheme
 * @property string $cert_claim_pct
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class PackingList extends Model
{
    protected $table = 'packing_lists';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'sales_order_id',
        'customer_id',
        'delivery_address_id',
        'packed_on',
        'total_cartons',
        'total_qty',
        'gross_weight_kg',
        'net_weight_kg',
        'status',
        'cert_claim_scheme',
        'cert_claim_pct',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sales_order_id' => 'integer',
            'customer_id' => 'integer',
            'delivery_address_id' => 'integer',
            'packed_on' => 'date',
            'total_cartons' => 'integer',
            'total_qty' => 'decimal:6',
            'gross_weight_kg' => 'decimal:3',
            'net_weight_kg' => 'decimal:3',
            'cert_claim_pct' => 'decimal:4',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }
}
