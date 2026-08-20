<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Supplier;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $number
 * @property string $source
 * @property int|null $qc_inspection_id
 * @property int|null $job_card_id
 * @property int|null $supplier_id
 * @property int|null $customer_id
 * @property \Illuminate\Support\Carbon $raised_on
 * @property string $description
 * @property string $severity
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $closed_on
 * @property int|null $raised_by
 * @property int|null $owner_id
 */
class Ncr extends Model
{
    use Auditable;

    public const OPEN = 'open';

    public const INVESTIGATING = 'investigating';

    public const ACTION_TAKEN = 'action_taken';

    public const VERIFIED = 'verified';

    public const CLOSED = 'closed';

    protected $table = 'ncrs';

    public $timestamps = false;

    protected $fillable = [
        'number',
        'source',
        'qc_inspection_id',
        'job_card_id',
        'supplier_id',
        'customer_id',
        'raised_on',
        'description',
        'severity',
        'status',
        'closed_on',
        'raised_by',
        'owner_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qc_inspection_id' => 'integer',
            'job_card_id' => 'integer',
            'supplier_id' => 'integer',
            'customer_id' => 'integer',
            'raised_on' => 'date:Y-m-d',
            'closed_on' => 'date:Y-m-d',
            'raised_by' => 'integer',
            'owner_id' => 'integer',
        ];
    }

    /** @return BelongsTo<QcInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class, 'qc_inspection_id');
    }

    /** @return BelongsTo<JobCard, $this> */
    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class, 'job_card_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<Capa, $this> */
    public function capas(): HasMany
    {
        return $this->hasMany(Capa::class);
    }

    /**
     * Whether the QC disposition still needs an operational action that this NCR must not
     * pretend is done. P1-3 already applied rework and the scrap freeze; downgrade's
     * second-quality lot conversion is not implemented and therefore stays pending.
     *
     * @return array{kind: string, status: 'applied'|'pending'|'none', detail: string}
     */
    public function pendingAction(): array
    {
        $this->loadMissing('inspection');

        $disposition = $this->inspection?->disposition;

        if ($disposition === null) {
            return [
                'kind' => 'none',
                'status' => 'none',
                'detail' => 'No QC disposition is attached to this NCR.',
            ];
        }

        return match ($disposition) {
            'rework' => [
                'kind' => 'rework',
                'status' => 'applied',
                'detail' => 'Rework was applied at QC rejection: the flagged operation reopened through the job-card state machine.',
            ],
            'scrap' => [
                'kind' => 'scrap',
                'status' => 'applied',
                'detail' => 'Rejected finished-goods lots were blocked at QC rejection. Waste posting is not performed from the NCR.',
            ],
            'concession' => filled($this->inspection?->disposition_ref) ? [
                'kind' => 'concession',
                'status' => 'applied',
                'detail' => 'Concession recorded on the inspection with customer evidence.',
            ] : [
                'kind' => 'concession',
                'status' => 'pending',
                'detail' => 'Concession still needs customer evidence on the inspection (BR-33) before this NCR can close.',
            ],
            'downgrade' => [
                'kind' => 'downgrade',
                'status' => 'pending',
                'detail' => 'Downgrade to a second-quality lot is not implemented — this NCR cannot close while that action is pending.',
            ],
            default => [
                'kind' => $disposition,
                'status' => 'applied',
                'detail' => 'Disposition '.$disposition.' was recorded on the originating inspection.',
            ],
        };
    }
}
