<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $symbol
 * @property bool $is_base
 * @property int|null $base_key
 */
class Currency extends Model
{
    protected $table = 'currencies';

    public $timestamps = false;

    // `base_key` are STORED generated columns: MySQL writes them, the application never
    // does, and they are absent from $fillable for that reason (02-database-schema §5.1).

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'is_base',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_base' => 'boolean',
        ];
    }
}
