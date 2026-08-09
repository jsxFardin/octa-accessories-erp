<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $factory_unit_id
 * @property string $code
 * @property string $name
 * @property string $kind
 */
class Department extends Model
{
    protected $table = 'departments';

    public $timestamps = false;

    protected $fillable = [
        'factory_unit_id',
        'code',
        'name',
        'kind',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'factory_unit_id' => 'integer',
        ];
    }
}
