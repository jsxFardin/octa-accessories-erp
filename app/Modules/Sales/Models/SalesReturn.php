<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Finance\Models\SalesInvoice;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer return of goods that were delivered and invoiced.
 *
 * The invoice it names is never altered by it. A paid invoice stays paid, its
 * `received_amount` stays where it is, and its receipt allocations stay untouched — the money
 * arrived and history says so. The return records what came back; the credit that answers it
 * is a separate document raised afterwards.
 *
 * @property int $id
 * @property string|null $number
 * @property int $sales_invoice_id
 * @property int|null $delivery_challan_id
 * @property int $customer_id
 * @property int $warehouse_id
 * @property \Illuminate\Support\Carbon $returned_on
 * @property string $reason
 * @property string $status
 * @property int|null $approved_by
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SalesReturnLine> $lines
 */
class SalesReturn extends Model
{
    use Auditable;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    protected $table = 'sales_returns';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'sales_invoice_id',
        'delivery_challan_id',
        'customer_id',
        'warehouse_id',
        'returned_on',
        'reason',
        'status',
        'approved_by',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sales_invoice_id' => 'integer',
            'delivery_challan_id' => 'integer',
            'customer_id' => 'integer',
            'warehouse_id' => 'integer',
            'returned_on' => 'date:Y-m-d',
            'approved_by' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return HasMany<SalesReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<SalesInvoice, $this> */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    /** The document's own reference, for messages raised before it has a number (BR-34). */
    public function reference(): string
    {
        return $this->number ?? "draft return #{$this->id}";
    }
}
