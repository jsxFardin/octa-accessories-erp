<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Supplier;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PR-7 — goods sent back to a supplier out of a posted receipt.
 *
 * @property int $id
 * @property string|null $number
 * @property int|null $grn_id
 * @property int $supplier_id
 * @property \Illuminate\Support\Carbon $returned_on
 * @property string $reason
 * @property string|null $debit_note_no
 * @property string $status
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Grn|null $grn
 * @property-read Supplier|null $supplier
 * @property-read User|null $creator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PurchaseReturnLine> $lines
 */
class PurchaseReturn extends Model
{
    use Auditable;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    protected $table = 'purchase_returns';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'grn_id',
        'supplier_id',
        'returned_on',
        'reason',
        'debit_note_no',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'grn_id' => 'integer',
            'supplier_id' => 'integer',
            'returned_on' => 'date:Y-m-d',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Grn, $this> */
    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PurchaseReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class)->orderBy('line_no');
    }
}
