<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $category
 * @property bool $is_planned
 */
class DowntimeReason extends Model
{
    protected $table = 'downtime_reasons';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'category',
        'is_planned',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_planned' => 'boolean',
        ];
    }
}
