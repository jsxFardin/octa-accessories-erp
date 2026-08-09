<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $physical_count_id
 * @property int $lot_id
 * @property string $system_qty
 * @property string|null $counted_qty
 * @property string|null $variance_qty
 * @property int|null $counted_by
 * @property string|null $remarks
 */
class PhysicalCountLine extends Model
{
    protected $table = 'physical_count_lines';

    public $timestamps = false;

    // `variance_qty` are STORED generated columns: MySQL writes them, the application never
    // does, and they are absent from $fillable for that reason (02-database-schema §5.1).

    protected $fillable = [
        'physical_count_id',
        'lot_id',
        'system_qty',
        'counted_qty',
        'counted_by',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'physical_count_id' => 'integer',
            'lot_id' => 'integer',
            'system_qty' => 'decimal:6',
            'counted_qty' => 'decimal:6',
            'counted_by' => 'integer',
        ];
    }
}
