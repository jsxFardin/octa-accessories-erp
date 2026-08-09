<?php

declare(strict_types=1);

namespace App\Modules\Product\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gate 1. The single most consequential row in the system.
 *
 * A2 — at most one version of an artwork is `approved` at any time, enforced by
 * `approved_key`, a STORED generated column that evaluates to the artwork id when approved
 * and to NULL otherwise, under a plain UNIQUE index. MySQL treats NULLs as distinct, so only
 * approved rows compete. Combined with `job_cards.artwork_version_id NOT NULL`, there is no
 * code path that releases production against an unapproved design.
 *
 * A3 — the file is immutable after upload; a correction is a new version. `checksum_sha256`
 * proves the file the customer approved is the file that reached plate-making.
 *
 * @property int $id
 * @property int $artwork_id
 * @property int $version_no
 * @property string $status
 * @property string $file_path
 * @property string|null $file_format
 * @property string|null $preview_path
 * @property string|null $checksum_sha256
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $approved_by
 * @property string|null $customer_ref
 * @property string|null $rejection_reason
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $approved_key
 */
class ArtworkVersion extends Model
{
    use Auditable;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SUPERSEDED = 'superseded';

    protected $table = 'artwork_versions';

    public const UPDATED_AT = null;

    // `approved_key` is a STORED generated column: MySQL writes it, the application never
    // does, and it is absent from $fillable for that reason (02-database-schema §5.1).

    protected $fillable = [
        'artwork_id',
        'version_no',
        'status',
        'file_path',
        'file_format',
        'preview_path',
        'checksum_sha256',
        'submitted_at',
        'approved_at',
        'approved_by',
        'customer_ref',
        'rejection_reason',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'artwork_id' => 'integer',
            'version_no' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'approved_by' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Artwork, $this> */
    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    /** @param Builder<$this> $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', self::APPROVED);
    }

    /**
     * A4 — an approved version cannot be deleted while a job card references it.
     */
    public function isReferencedByProduction(): bool
    {
        return \App\Modules\Manufacturing\Models\JobCard::query()
            ->where('artwork_version_id', $this->getKey())
            ->exists();
    }
}
