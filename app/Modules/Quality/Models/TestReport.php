<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int|null $lot_id
 * @property int|null $job_card_id
 * @property int|null $product_id
 * @property int|null $customer_id
 * @property \Illuminate\Support\Carbon $tested_on
 * @property int|null $technician_id
 * @property string $overall_result
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $issued_at
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class TestReport extends Model
{
    protected $table = 'test_reports';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'lot_id',
        'job_card_id',
        'product_id',
        'customer_id',
        'tested_on',
        'technician_id',
        'overall_result',
        'status',
        'issued_at',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lot_id' => 'integer',
            'job_card_id' => 'integer',
            'product_id' => 'integer',
            'customer_id' => 'integer',
            'tested_on' => 'date',
            'technician_id' => 'integer',
            'issued_at' => 'datetime',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }
}
