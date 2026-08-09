<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $number
 * @property int $revision_no
 * @property int|null $inquiry_id
 * @property int $customer_id
 * @property \Illuminate\Support\Carbon $quotation_date
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property int $currency_id
 * @property string $exchange_rate
 * @property int|null $payment_term_id
 * @property int|null $merchandiser_id
 * @property string $subtotal
 * @property string $tax_amount
 * @property string $total
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property string|null $reject_reason
 * @property string|null $terms
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class Quotation extends Model
{
    protected $table = 'quotations';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'revision_no',
        'inquiry_id',
        'customer_id',
        'quotation_date',
        'valid_until',
        'currency_id',
        'exchange_rate',
        'payment_term_id',
        'merchandiser_id',
        'subtotal',
        'tax_amount',
        'total',
        'status',
        'sent_at',
        'decided_at',
        'reject_reason',
        'terms',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision_no' => 'integer',
            'inquiry_id' => 'integer',
            'customer_id' => 'integer',
            'quotation_date' => 'date',
            'valid_until' => 'date',
            'currency_id' => 'integer',
            'exchange_rate' => 'decimal:8',
            'payment_term_id' => 'integer',
            'merchandiser_id' => 'integer',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Customer::class, 'customer_id');
    }

    /** @return HasMany<QuotationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class, 'quotation_id')->orderBy('line_no');
    }
}
