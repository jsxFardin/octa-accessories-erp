<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tool_id
 * @property int|null $job_card_operation_id
 * @property int $impressions
 * @property \Illuminate\Support\Carbon $used_on
 * @property string|null $remarks
 */
class ToolUsage extends Model
{
    protected $table = 'tool_usages';

    public $timestamps = false;

    protected $fillable = [
        'tool_id',
        'job_card_operation_id',
        'impressions',
        'used_on',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tool_id' => 'integer',
            'job_card_operation_id' => 'integer',
            'impressions' => 'integer',
            'used_on' => 'date:Y-m-d',
        ];
    }
}
