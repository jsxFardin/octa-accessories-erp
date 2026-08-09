<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property string $source
 * @property int|null $qc_inspection_id
 * @property int|null $job_card_id
 * @property int|null $supplier_id
 * @property int|null $customer_id
 * @property \Illuminate\Support\Carbon $raised_on
 * @property string $description
 * @property string $severity
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $closed_on
 * @property int|null $raised_by
 * @property int|null $owner_id
 */
class Ncr extends Model
{
    protected $table = 'ncrs';

    public $timestamps = false;

    protected $fillable = [
        'number',
        'source',
        'qc_inspection_id',
        'job_card_id',
        'supplier_id',
        'customer_id',
        'raised_on',
        'description',
        'severity',
        'status',
        'closed_on',
        'raised_by',
        'owner_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qc_inspection_id' => 'integer',
            'job_card_id' => 'integer',
            'supplier_id' => 'integer',
            'customer_id' => 'integer',
            'raised_on' => 'date',
            'closed_on' => 'date',
            'raised_by' => 'integer',
            'owner_id' => 'integer',
        ];
    }
}
