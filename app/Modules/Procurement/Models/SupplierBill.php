<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $number
 * @property int $supplier_id
 * @property int|null $po_id
 * @property int|null $grn_id
 * @property string $bill_no
 * @property \Illuminate\Support\Carbon $bill_date
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property int $currency_id
 * @property string $exchange_rate
 * @property string $subtotal
 * @property string $tax_amount
 * @property string $total
 * @property string $paid_amount
 * @property string $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class SupplierBill extends Model
{
    use Auditable;

    protected $table = 'supplier_bills';

    public const UPDATED_AT = null;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const PARTIALLY_PAID = 'partially_paid';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const NON_TERMINAL = [self::DRAFT, self::APPROVED, self::PARTIALLY_PAID];

    protected $fillable = [
        'number',
        'supplier_id',
        'po_id',
        'grn_id',
        'bill_no',
        'bill_date',
        'due_date',
        'currency_id',
        'exchange_rate',
        'subtotal',
        'tax_amount',
        'total',
        'paid_amount',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'po_id' => 'integer',
            'grn_id' => 'integer',
            'bill_date' => 'date',
            'due_date' => 'date',
            'currency_id' => 'integer',
            'exchange_rate' => 'decimal:8',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return HasMany<SupplierBillLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class, 'supplier_bill_id');
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Supplier::class, 'supplier_id');
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    /** @return BelongsTo<Grn, $this> */
    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class, 'grn_id');
    }

    /** @return BelongsTo<\App\Models\User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
