<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int $warehouse_id
 * @property \Illuminate\Support\Carbon $counted_on
 * @property string $status
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class PhysicalCount extends Model
{
    protected $table = 'physical_counts';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'warehouse_id',
        'counted_on',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'warehouse_id' => 'integer',
            'counted_on' => 'date',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
