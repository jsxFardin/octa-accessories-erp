<?php

declare(strict_types=1);

namespace App\Support\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $commentable_type
 * @property int $commentable_id
 * @property int|null $parent_id
 * @property string $body
 * @property bool $is_external
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class Comment extends Model
{
    protected $table = 'comments';

    public const UPDATED_AT = null;

    protected $fillable = [
        'commentable_type',
        'commentable_id',
        'parent_id',
        'body',
        'is_external',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'commentable_id' => 'integer',
            'parent_id' => 'integer',
            'is_external' => 'boolean',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
