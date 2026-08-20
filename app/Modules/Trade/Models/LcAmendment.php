<?php

declare(strict_types=1);

namespace App\Modules\Trade\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An amendment to an open LC — more money, a later shipment date, a later expiry.
 *
 * Appended, never merged into the LC row. The bank charges for each one, and "why did this
 * credit cost another 18,000 taka" is answered by this table or not at all.
 *
 * @property int $id
 * @property int $lc_id
 * @property int $amendment_no
 * @property \Illuminate\Support\Carbon $amended_on
 * @property string $amount_delta
 * @property \Illuminate\Support\Carbon|null $new_expiry_date
 * @property \Illuminate\Support\Carbon|null $new_last_shipment_date
 * @property string $charges_amount
 * @property string|null $narrative
 */
class LcAmendment extends Model
{
    protected $table = 'lc_amendments';

    public const UPDATED_AT = null;

    protected $fillable = [
        'lc_id', 'amendment_no', 'amended_on', 'amount_delta',
        'new_expiry_date', 'new_last_shipment_date', 'charges_amount', 'narrative', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lc_id' => 'integer',
            'amendment_no' => 'integer',
            'amended_on' => 'date:Y-m-d',
            'amount_delta' => 'decimal:4',
            'new_expiry_date' => 'date:Y-m-d',
            'new_last_shipment_date' => 'date:Y-m-d',
            'charges_amount' => 'decimal:4',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<LetterOfCredit, $this> */
    public function letterOfCredit(): BelongsTo
    {
        return $this->belongsTo(LetterOfCredit::class, 'lc_id');
    }
}
