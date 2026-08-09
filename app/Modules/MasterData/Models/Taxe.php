<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $rate_pct
 * @property string $kind
 * @property bool $is_active
 */
class Taxe extends Model
{
    protected $table = 'taxes';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'rate_pct',
        'kind',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rate_pct' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
