<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $method
 * @property string $scale
 * @property string|null $default_pass_value
 * @property string|null $unit
 * @property bool $is_active
 */
class LabTest extends Model
{
    protected $table = 'lab_tests';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'method',
        'scale',
        'default_pass_value',
        'unit',
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
