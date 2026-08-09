<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $factory_unit_id
 * @property string $code
 * @property string $name
 * @property string $starts_at
 * @property string $ends_at
 * @property int $break_minutes
 */
class Shift extends Model
{
    protected $table = 'shifts';

    public $timestamps = false;

    protected $fillable = [
        'factory_unit_id',
        'code',
        'name',
        'starts_at',
        'ends_at',
        'break_minutes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'factory_unit_id' => 'integer',
            'starts_at' => 'string',
            'ends_at' => 'string',
            'break_minutes' => 'integer',
        ];
    }
}
