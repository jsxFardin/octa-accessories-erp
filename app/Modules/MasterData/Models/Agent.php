<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $commission_pct
 * @property bool $is_active
 */
class Agent extends Model
{
    protected $table = 'agents';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'commission_pct',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'commission_pct' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
