<?php

declare(strict_types=1);

namespace App\Modules\Sampling\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Sales\Models\Inquiry;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $number
 * @property int $customer_id
 * @property int|null $inquiry_id
 * @property int|null $sales_order_id
 * @property string $sample_type
 * @property \Illuminate\Support\Carbon $requested_on
 * @property \Illuminate\Support\Carbon|null $required_by
 * @property bool $is_chargeable
 * @property string $charge_amount
 * @property string $status
 * @property int|null $merchandiser_id
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 * @property-read Customer|null $customer
 * @property-read Inquiry|null $inquiry
 * @property-read SalesOrder|null $salesOrder
 * @property-read User|null $creator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SampleRequestLine> $lines
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SampleDispatch> $dispatches
 */
class SampleRequest extends Model
{
    use Auditable;

    public const REQUESTED = 'requested';

    public const IN_DEVELOPMENT = 'in_development';

    public const IN_PRODUCTION = 'in_production';

    public const READY = 'ready';

    public const DISPATCHED = 'dispatched';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** AS-5 AC1 — the kinds a sample is asked for as (sample_requests_type_chk). */
    public const TYPES = ['proto', 'approval', 'colour', 'size_set', 'pre_production', 'shipment', 'counter'];

    /** Still open to edits: nothing has been made against it yet. */
    public const EDITABLE = [self::REQUESTED, self::IN_DEVELOPMENT];

    protected $table = 'sample_requests';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'customer_id',
        'inquiry_id',
        'sales_order_id',
        'sample_type',
        'requested_on',
        'required_by',
        'is_chargeable',
        'charge_amount',
        'status',
        'merchandiser_id',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'inquiry_id' => 'integer',
            'sales_order_id' => 'integer',
            'requested_on' => 'date:Y-m-d',
            'required_by' => 'date:Y-m-d',
            'is_chargeable' => 'boolean',
            'charge_amount' => 'decimal:4',
            'merchandiser_id' => 'integer',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Inquiry, $this> */
    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<SampleRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SampleRequestLine::class)->orderBy('line_no');
    }

    /** @return HasMany<SampleDispatch, $this> */
    public function dispatches(): HasMany
    {
        return $this->hasMany(SampleDispatch::class)->orderBy('id');
    }
}
