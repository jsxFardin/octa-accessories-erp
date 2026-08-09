<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int|null $pr_id
 * @property \Illuminate\Support\Carbon $issued_on
 * @property \Illuminate\Support\Carbon|null $respond_by
 * @property string $status
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class SupplierRfq extends Model
{
    protected $table = 'supplier_rfqs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'pr_id',
        'issued_on',
        'respond_by',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pr_id' => 'integer',
            'issued_on' => 'date',
            'respond_by' => 'date',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
