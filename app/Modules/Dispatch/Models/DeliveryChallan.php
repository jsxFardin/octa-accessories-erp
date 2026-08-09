<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string|null $number
 * @property int|null $packing_list_id
 * @property int|null $sales_order_id
 * @property int $customer_id
 * @property int|null $delivery_address_id
 * @property int|null $trip_id
 * @property \Illuminate\Support\Carbon $challan_date
 * @property string $mode
 * @property string|null $courier_name
 * @property string|null $tracking_no
 * @property int $total_cartons
 * @property string $total_qty
 * @property string $status
 * @property string|null $gate_pass_no
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class DeliveryChallan extends Model
{
    protected $table = 'delivery_challans';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'packing_list_id',
        'sales_order_id',
        'customer_id',
        'delivery_address_id',
        'trip_id',
        'challan_date',
        'mode',
        'courier_name',
        'tracking_no',
        'total_cartons',
        'total_qty',
        'status',
        'gate_pass_no',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'packing_list_id' => 'integer',
            'sales_order_id' => 'integer',
            'customer_id' => 'integer',
            'delivery_address_id' => 'integer',
            'trip_id' => 'integer',
            'challan_date' => 'date',
            'total_cartons' => 'integer',
            'total_qty' => 'decimal:6',
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
