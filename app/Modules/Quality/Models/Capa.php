<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ncr_id
 * @property string $kind
 * @property string|null $root_cause
 * @property string $action
 * @property int|null $responsible_id
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property \Illuminate\Support\Carbon|null $completed_on
 * @property string|null $effectiveness
 * @property string $status
 */
class Capa extends Model
{
    use Auditable;

    public const KIND_CORRECTIVE = 'corrective';

    public const KIND_PREVENTIVE = 'preventive';

    public const OPEN = 'open';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const VERIFIED = 'verified';

    protected $table = 'capas';

    public $timestamps = false;

    protected $fillable = [
        'ncr_id',
        'kind',
        'root_cause',
        'action',
        'responsible_id',
        'due_date',
        'completed_on',
        'effectiveness',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ncr_id' => 'integer',
            'responsible_id' => 'integer',
            'due_date' => 'date:Y-m-d',
            'completed_on' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<Ncr, $this> */
    public function ncr(): BelongsTo
    {
        return $this->belongsTo(Ncr::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }
}
