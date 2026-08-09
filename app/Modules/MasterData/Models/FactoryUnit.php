<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property string $timezone
 * @property bool $is_active
 */
class FactoryUnit extends Model
{
    protected $table = 'factory_units';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'address',
        'timezone',
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
