<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int $job_card_id
 * @property int $warehouse_id
 * @property \Illuminate\Support\Carbon $issued_on
 * @property string $issue_type
 * @property string $status
 * @property int|null $issued_by
 * @property int|null $received_by
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 */
class MaterialIssue extends Model
{
    use Auditable;

    protected $table = 'material_issues';

    public const TYPE_ISSUE = 'issue';

    public const TYPE_RETURN = 'return';

    public const POSTED = 'posted';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'job_card_id',
        'warehouse_id',
        'issued_on',
        'issue_type',
        'status',
        'issued_by',
        'received_by',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'job_card_id' => 'integer',
            'warehouse_id' => 'integer',
            'issued_on' => 'date:Y-m-d',
            'issued_by' => 'integer',
            'received_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
