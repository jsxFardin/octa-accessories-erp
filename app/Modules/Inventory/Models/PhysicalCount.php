<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Warehouse;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * IN-6 — a warehouse-wide blind physical count. Lots are frozen while counting; variances
 * post through StockPostingService as `count_variance` movements.
 *
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
    use Auditable;

    protected $table = 'physical_counts';

    public const UPDATED_AT = null;

    public const OPEN = 'open';

    public const COUNTING = 'counting';

    public const RECONCILED = 'reconciled';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const NON_TERMINAL = [
        self::OPEN,
        self::COUNTING,
        self::RECONCILED,
    ];

    protected $fillable = [
        'warehouse_id',
        'counted_on',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'warehouse_id' => 'integer',
            'counted_on' => 'date:Y-m-d',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return HasMany<PhysicalCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PhysicalCountLine::class)->orderBy('lot_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
