<?php

declare(strict_types=1);

namespace App\Modules\Sampling\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sample_request_line_id
 * @property string $decision
 * @property \Illuminate\Support\Carbon $decided_on
 * @property string|null $customer_ref
 * @property string|null $comments
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User|null $recorder
 */
class SampleApproval extends Model
{
    /** AS-7 AC1 — the three answers a customer gives. */
    public const DECISIONS = ['approved', 'approved_with_comments', 'rejected'];

    protected $table = 'sample_approvals';

    public const UPDATED_AT = null;

    protected $fillable = [
        'sample_request_line_id',
        'decision',
        'decided_on',
        'customer_ref',
        'comments',
        'recorded_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sample_request_line_id' => 'integer',
            'decided_on' => 'date:Y-m-d',
            'recorded_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
