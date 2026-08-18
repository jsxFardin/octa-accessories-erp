<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    // `variance_qty` is a STORED generated column — never fillable (02-database-schema §5.1).

    protected $fillable = [
        'physical_count_id',
        'lot_id',
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
            'variance_qty' => 'decimal:6',
            'counted_by' => 'integer',
        ];
    }

    /** @return BelongsTo<PhysicalCount, $this> */
    public function physicalCount(): BelongsTo
    {
        return $this->belongsTo(PhysicalCount::class, 'physical_count_id');
    }

    /** @return BelongsTo<StockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
