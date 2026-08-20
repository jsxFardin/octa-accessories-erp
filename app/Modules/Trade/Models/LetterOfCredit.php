<?php

declare(strict_types=1);

namespace App\Modules\Trade\Models;

use App\Modules\MasterData\Models\Supplier;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The credit the bank opens in a supplier's favour.
 *
 * Two numbers, and they are not the same thing: `number` is ours, allocated when the
 * application leaves draft (BR-34); `lc_no` is what the bank issues, and does not exist until
 * the LC is actually opened. Reports that quote one where the other is meant are how a
 * shipment gets matched to the wrong credit.
 *
 * @property int $id
 * @property string|null $number
 * @property string|null $lc_no
 * @property string $kind
 * @property int $supplier_id
 * @property int|null $bank_account_id
 * @property int $currency_id
 * @property string $exchange_rate
 * @property string $amount
 * @property string $tolerance_pct
 * @property string $margin_pct
 * @property int $tenor_days
 * @property string $charges_amount
 * @property \Illuminate\Support\Carbon|null $applied_on
 * @property \Illuminate\Support\Carbon|null $issued_on
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property \Illuminate\Support\Carbon|null $last_shipment_date
 * @property string|null $incoterm
 * @property string|null $port_of_loading
 * @property string|null $port_of_discharge
 * @property string $status
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class LetterOfCredit extends Model
{
    use Auditable;

    /** The kinds a Bangladeshi importer actually uses, including the non-LC payment terms. */
    public const KINDS = ['sight', 'usance', 'back_to_back', 'tt', 'da', 'dp'];

    public const STATUSES = ['draft', 'applied', 'opened', 'shipped', 'retired', 'closed', 'cancelled'];

    protected $table = 'letters_of_credit';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'lc_no',
        'kind',
        'supplier_id',
        'bank_account_id',
        'currency_id',
        'exchange_rate',
        'amount',
        'tolerance_pct',
        'margin_pct',
        'tenor_days',
        'charges_amount',
        'applied_on',
        'issued_on',
        'expiry_date',
        'last_shipment_date',
        'incoterm',
        'port_of_loading',
        'port_of_discharge',
        'status',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'bank_account_id' => 'integer',
            'currency_id' => 'integer',
            'exchange_rate' => 'decimal:8',
            'amount' => 'decimal:4',
            'tolerance_pct' => 'decimal:4',
            'margin_pct' => 'decimal:4',
            'tenor_days' => 'integer',
            'charges_amount' => 'decimal:4',
            'applied_on' => 'date:Y-m-d',
            'issued_on' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'last_shipment_date' => 'date:Y-m-d',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return HasMany<LcAmendment, $this> */
    public function amendments(): HasMany
    {
        return $this->hasMany(LcAmendment::class, 'lc_id');
    }

    /** @return HasMany<ImportShipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(ImportShipment::class, 'lc_id');
    }

    /** @return BelongsToMany<PurchaseOrder, $this> */
    public function purchaseOrders(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseOrder::class, 'lc_purchase_orders', 'lc_id', 'po_id')
            ->withPivot('covered_amount');
    }

    /**
     * Face value plus every amendment — the figure the bank is actually holding.
     *
     * Amendments are appended rather than edited into `amount` so the history of what was
     * increased, when, and what it cost stays readable.
     */
    public function currentAmount(): float
    {
        return round((float) $this->amount + (float) $this->amendments()->sum('amount_delta'), 4);
    }

    /** The expiry as last amended, which is the one that matters. */
    public function effectiveExpiry(): ?string
    {
        // `value()` on a dated column comes back as a Carbon through the model's casts, so it
        // is normalised here rather than trusted to already be a string.
        $amended = $this->amendments()
            ->whereNotNull('new_expiry_date')
            ->orderByDesc('amendment_no')
            ->value('new_expiry_date');

        if ($amended !== null) {
            return $amended instanceof \DateTimeInterface ? $amended->format('Y-m-d') : (string) $amended;
        }

        return $this->expiry_date?->toDateString();
    }
}
