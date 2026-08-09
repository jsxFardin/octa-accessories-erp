<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $trip_id
 * @property int $sequence_no
 * @property int|null $delivery_challan_id
 * @property int|null $customer_id
 * @property int|null $address_id
 * @property \Illuminate\Support\Carbon|null $planned_at
 * @property \Illuminate\Support\Carbon|null $arrived_at
 * @property \Illuminate\Support\Carbon|null $departed_at
 * @property string $status
 * @property string|null $received_by_name
 * @property string|null $signature_path
 * @property string|null $photo_path
 * @property \Illuminate\Support\Carbon|null $pod_captured_at
 * @property string|null $failure_reason
 */
class TripStop extends Model
{
    protected $table = 'trip_stops';

    public $timestamps = false;

    protected $fillable = [
        'trip_id',
        'sequence_no',
        'delivery_challan_id',
        'customer_id',
        'address_id',
        'planned_at',
        'arrived_at',
        'departed_at',
        'status',
        'received_by_name',
        'signature_path',
        'photo_path',
        'pod_captured_at',
        'failure_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trip_id' => 'integer',
            'sequence_no' => 'integer',
            'delivery_challan_id' => 'integer',
            'customer_id' => 'integer',
            'address_id' => 'integer',
            'planned_at' => 'datetime',
            'arrived_at' => 'datetime',
            'departed_at' => 'datetime',
            'pod_captured_at' => 'datetime',
        ];
    }
}
