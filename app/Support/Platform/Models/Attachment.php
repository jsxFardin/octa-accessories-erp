<?php

declare(strict_types=1);

namespace App\Support\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string $collection
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string|null $checksum_sha256
 * @property int|null $uploaded_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class Attachment extends Model
{
    protected $table = 'attachments';

    public const UPDATED_AT = null;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'collection',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'uploaded_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attachable_id' => 'integer',
            'size_bytes' => 'integer',
            'uploaded_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
