<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $process
 * @property string $severity
 * @property bool $is_active
 */
class Defect extends Model
{
    protected $table = 'defects';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'process',
        'severity',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
