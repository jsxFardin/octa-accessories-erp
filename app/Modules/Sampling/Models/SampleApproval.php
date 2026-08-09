<?php

declare(strict_types=1);

namespace App\Modules\Sampling\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sample_request_line_id
 * @property string $decision
 * @property \Illuminate\Support\Carbon $decided_on
 * @property string|null $customer_ref
 * @property string|null $comments
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class SampleApproval extends Model
{
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
            'decided_on' => 'date',
            'recorded_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
