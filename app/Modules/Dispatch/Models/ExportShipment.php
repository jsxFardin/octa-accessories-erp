<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Trade\Models\LetterOfCredit;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A consignment leaving the country (DF-6): the transport facts once, for one or more delivery
 * notes, the documents that travel with it, and the credit it ships under.
 *
 * @property int $id
 * @property string|null $number
 * @property int $customer_id
 * @property int|null $lc_id
 * @property string $mode
 * @property string|null $forwarder
 * @property string|null $carrier
 * @property string|null $vessel_or_flight
 * @property string|null $container_no
 * @property string|null $seal_no
 * @property string|null $transport_doc_no
 * @property \Illuminate\Support\Carbon|null $transport_doc_date
 * @property string|null $exp_form_no
 * @property \Illuminate\Support\Carbon|null $exp_form_date
 * @property string|null $ud_no
 * @property string|null $port_of_loading
 * @property string|null $port_of_discharge
 * @property string|null $final_destination
 * @property string|null $incoterm
 * @property \Illuminate\Support\Carbon|null $etd
 * @property \Illuminate\Support\Carbon|null $eta
 * @property \Illuminate\Support\Carbon|null $shipped_on
 * @property \Illuminate\Support\Carbon|null $presented_on
 * @property \Illuminate\Support\Carbon|null $delivered_on
 * @property int $currency_id
 * @property string $exchange_rate
 * @property string $goods_value
 * @property string|null $gross_kg
 * @property string|null $net_kg
 * @property string|null $cbm
 * @property string $status
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 * @property-read Customer|null $customer
 * @property-read LetterOfCredit|null $letterOfCredit
 * @property-read Currency|null $currency
 * @property-read User|null $creator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DeliveryChallan> $challans
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ExportDocument> $documents
 */
class ExportShipment extends Model
{
    use Auditable;

    public const DRAFT = 'draft';

    public const BOOKED = 'booked';

    public const SHIPPED = 'shipped';

    public const PRESENTED = 'presented';

    public const DELIVERED = 'delivered';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::BOOKED, self::SHIPPED, self::PRESENTED, self::DELIVERED, self::CLOSED, self::CANCELLED];

    public const MODES = ['sea', 'air', 'road', 'courier'];

    /** Delivery notes may still be added and the facts still typed. */
    public const EDITABLE = [self::DRAFT, self::BOOKED];

    protected $table = 'export_shipments';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number', 'customer_id', 'lc_id', 'mode', 'forwarder', 'carrier', 'vessel_or_flight', 'container_no', 'seal_no',
        'transport_doc_no', 'transport_doc_date', 'exp_form_no', 'exp_form_date', 'ud_no',
        'port_of_loading', 'port_of_discharge', 'final_destination', 'incoterm', 'etd', 'eta',
        'shipped_on', 'presented_on', 'delivered_on', 'currency_id', 'exchange_rate', 'goods_value',
        'gross_kg', 'net_kg', 'cbm', 'status', 'remarks', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'lc_id' => 'integer',
            'currency_id' => 'integer',
            'transport_doc_date' => 'date:Y-m-d',
            'exp_form_date' => 'date:Y-m-d',
            'etd' => 'date:Y-m-d',
            'eta' => 'date:Y-m-d',
            'shipped_on' => 'date:Y-m-d',
            'presented_on' => 'date:Y-m-d',
            'delivered_on' => 'date:Y-m-d',
            'exchange_rate' => 'decimal:8',
            'goods_value' => 'decimal:4',
            'gross_kg' => 'decimal:3',
            'net_kg' => 'decimal:3',
            'cbm' => 'decimal:3',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<LetterOfCredit, $this> */
    public function letterOfCredit(): BelongsTo
    {
        return $this->belongsTo(LetterOfCredit::class, 'lc_id');
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<DeliveryChallan, $this> */
    public function challans(): HasMany
    {
        return $this->hasMany(DeliveryChallan::class, 'export_shipment_id')->orderBy('id');
    }

    /** @return HasMany<ExportDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ExportDocument::class, 'export_shipment_id')->orderBy('id');
    }
}
