<?php

declare(strict_types=1);

namespace App\Modules\Trade\Models;

use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string $direction
 * @property int|null $supplier_id
 * @property int|null $customer_id
 * @property int|null $master_lc_id
 * @property string|null $issuing_bank
 * @property int|null $advising_bank_account_id
 * @property int $latest_presentation_days
 * @property bool $partial_shipment
 * @property bool $transhipment
 * @property \Illuminate\Support\Carbon|null $presented_on
 * @property \Illuminate\Support\Carbon|null $realised_on
 * @property string|null $realisation_ref
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

    public const IMPORT = 'import';

    public const EXPORT = 'export';

    /** The export side: a customer's bank opens the credit in our favour, and we are the beneficiary. */
    public const EXPORT_KINDS = ['sight', 'usance', 'tt', 'da', 'dp'];

    public const ADVISED = 'advised';

    public const ACCEPTED = 'accepted';

    public const SHIPPED = 'shipped';

    public const PRESENTED = 'presented';

    public const DISCREPANT = 'discrepant';

    public const REALISED = 'realised';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    public const EXPORT_STATUSES = [self::ADVISED, self::ACCEPTED, self::SHIPPED, self::PRESENTED, self::DISCREPANT, self::REALISED, self::CLOSED, self::CANCELLED];

    /** Export stages where the dates are still deadlines and an amendment still makes sense. */
    public const EXPORT_LIVE = [self::ADVISED, self::ACCEPTED, self::SHIPPED, self::PRESENTED, self::DISCREPANT];

    protected $table = 'letters_of_credit';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'lc_no',
        'kind',
        'direction',
        'supplier_id',
        'customer_id',
        'master_lc_id',
        'issuing_bank',
        'bank_account_id',
        'advising_bank_account_id',
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
        'latest_presentation_days',
        'partial_shipment',
        'transhipment',
        'presented_on',
        'realised_on',
        'realisation_ref',
        'status',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'customer_id' => 'integer',
            'master_lc_id' => 'integer',
            'bank_account_id' => 'integer',
            'advising_bank_account_id' => 'integer',
            'latest_presentation_days' => 'integer',
            'partial_shipment' => 'boolean',
            'transhipment' => 'boolean',
            'presented_on' => 'date:Y-m-d',
            'realised_on' => 'date:Y-m-d',
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

    /**
     * Export side — the applicant.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Export side — our account the proceeds land in.
     *
     * @return BelongsTo<BankAccount, $this>
     */
    public function advisingBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'advising_bank_account_id');
    }

    /**
     * The export credit an import credit was opened back-to-back against.
     *
     * @return BelongsTo<LetterOfCredit, $this>
     */
    public function masterLc(): BelongsTo
    {
        return $this->belongsTo(self::class, 'master_lc_id');
    }

    /** @return BelongsToMany<SalesOrder, $this> */
    public function salesOrders(): BelongsToMany
    {
        return $this->belongsToMany(SalesOrder::class, 'lc_sales_orders', 'lc_id', 'sales_order_id')->withPivot('covered_amount');
    }

    /** @param  Builder<LetterOfCredit>  $query */
    public function scopeImport(Builder $query): void
    {
        $query->where('direction', self::IMPORT);
    }

    /** @param  Builder<LetterOfCredit>  $query */
    public function scopeExport(Builder $query): void
    {
        $query->where('direction', self::EXPORT);
    }

    /**
     * BR-50 — the credit's own currency. Every letter of credit on this data is opened in USD,
     * and without the relation the screens fell back to the factory's currency and printed a
     * USD 250,000 credit as BDT 250,000.
     *
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
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
