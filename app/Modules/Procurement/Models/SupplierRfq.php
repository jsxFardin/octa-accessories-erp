<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PR-2 — a request for quotation issued against a requisition (or raised by hand).
 * Quotations come back from suppliers; selecting a winner pre-fills the purchase order.
 *
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
    use Auditable;

    protected $table = 'supplier_rfqs';

    public const UPDATED_AT = null;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'pr_id',
        'issued_on',
        'respond_by',
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

    /** @return HasMany<SupplierRfqLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierRfqLine::class, 'rfq_id')->orderBy('line_no');
    }

    /** @return HasMany<SupplierQuotation, $this> */
    public function quotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class, 'rfq_id');
    }

    /** @return BelongsTo<PurchaseRequisition, $this> */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'pr_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
