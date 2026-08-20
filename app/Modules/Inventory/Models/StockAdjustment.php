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
 * IN-5 — a signed correction against existing lots in one warehouse.
 *
 * Status never moves by assignment. Posting is the approval effect and the only step that
 * writes stock, through StockPostingService.
 *
 * @property int $id
 * @property string|null $number
 * @property int $warehouse_id
 * @property \Illuminate\Support\Carbon $adjusted_on
 * @property string $reason
 * @property string $status
 * @property int|null $approved_by
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class StockAdjustment extends Model
{
    use Auditable;

    protected $table = 'stock_adjustments';

    public const UPDATED_AT = null;

    public const DRAFT = 'draft';

    public const PENDING_APPROVAL = 'pending_approval';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'number',
        'warehouse_id',
        'adjusted_on',
        'reason',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'warehouse_id' => 'integer',
            'adjusted_on' => 'date:Y-m-d',
            'approved_by' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return HasMany<StockAdjustmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class)->orderBy('line_no');
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

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
