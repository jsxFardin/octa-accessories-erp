<?php

declare(strict_types=1);

namespace App\Modules\Sampling\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int $customer_id
 * @property int|null $inquiry_id
 * @property int|null $sales_order_id
 * @property string $sample_type
 * @property \Illuminate\Support\Carbon $requested_on
 * @property \Illuminate\Support\Carbon|null $required_by
 * @property bool $is_chargeable
 * @property string $charge_amount
 * @property string $status
 * @property int|null $merchandiser_id
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class SampleRequest extends Model
{
    protected $table = 'sample_requests';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'customer_id',
        'inquiry_id',
        'sales_order_id',
        'sample_type',
        'requested_on',
        'required_by',
        'is_chargeable',
        'charge_amount',
        'status',
        'merchandiser_id',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'inquiry_id' => 'integer',
            'sales_order_id' => 'integer',
            'requested_on' => 'date',
            'required_by' => 'date',
            'is_chargeable' => 'boolean',
            'charge_amount' => 'decimal:4',
            'merchandiser_id' => 'integer',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }
}
