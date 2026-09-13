<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money paid back to a customer against an approved credit note.
 *
 * @property int $id
 * @property string|null $number
 * @property int $credit_note_id
 * @property int $customer_id
 * @property int $currency_id
 * @property \Illuminate\Support\Carbon $refund_date
 * @property string $method
 * @property string|null $reference_no
 * @property string $amount
 * @property string|null $reason
 * @property string $status
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class Refund extends Model
{
    use Auditable;

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    protected $table = 'refunds';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'credit_note_id',
        'customer_id',
        'currency_id',
        'refund_date',
        'method',
        'reference_no',
        'amount',
        'reason',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credit_note_id' => 'integer',
            'customer_id' => 'integer',
            'currency_id' => 'integer',
            'refund_date' => 'date:Y-m-d',
            'amount' => 'decimal:4',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }
}
