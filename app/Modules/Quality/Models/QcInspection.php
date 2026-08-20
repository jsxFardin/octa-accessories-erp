<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Modules\Manufacturing\Models\JobCardOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $number
 * @property string $stage
 * @property int|null $job_card_id
 * @property int|null $job_card_operation_id
 * @property int|null $grn_line_id
 * @property int|null $lot_id
 * @property int|null $aql_plan_id
 * @property \Illuminate\Support\Carbon $inspected_on
 * @property int|null $inspector_id
 * @property int $lot_size
 * @property int $sample_size
 * @property int $critical_found
 * @property int $major_found
 * @property int $minor_found
 * @property int|null $accept_number
 * @property int|null $reject_number
 * @property string|null $dhu
 * @property string $result
 * @property string|null $disposition
 * @property string|null $disposition_ref
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class QcInspection extends Model
{
    protected $table = 'qc_inspections';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'stage',
        'job_card_id',
        'job_card_operation_id',
        'grn_line_id',
        'lot_id',
        'aql_plan_id',
        'inspected_on',
        'inspector_id',
        'lot_size',
        'sample_size',
        'critical_found',
        'major_found',
        'minor_found',
        'accept_number',
        'reject_number',
        'dhu',
        'result',
        'disposition',
        'disposition_ref',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'job_card_id' => 'integer',
            'job_card_operation_id' => 'integer',
            'grn_line_id' => 'integer',
            'lot_id' => 'integer',
            'aql_plan_id' => 'integer',
            'inspected_on' => 'date:Y-m-d',
            'inspector_id' => 'integer',
            'lot_size' => 'integer',
            'sample_size' => 'integer',
            'critical_found' => 'integer',
            'major_found' => 'integer',
            'minor_found' => 'integer',
            'accept_number' => 'integer',
            'reject_number' => 'integer',
            'dhu' => 'decimal:4',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<\App\Modules\Manufacturing\Models\JobCard, $this> */
    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Manufacturing\Models\JobCard::class, 'job_card_id');
    }

    /** @return BelongsTo<JobCardOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(JobCardOperation::class, 'job_card_operation_id');
    }

    /** @return HasMany<Ncr, $this> */
    public function ncrs(): HasMany
    {
        return $this->hasMany(Ncr::class, 'qc_inspection_id');
    }
}
