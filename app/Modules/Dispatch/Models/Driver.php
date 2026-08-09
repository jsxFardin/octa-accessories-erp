<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $employee_id
 * @property string $name
 * @property string|null $licence_no
 * @property \Illuminate\Support\Carbon|null $licence_expiry
 * @property string|null $phone
 * @property bool $is_active
 */
class Driver extends Model
{
    protected $table = 'drivers';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'name',
        'licence_no',
        'licence_expiry',
        'phone',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'licence_expiry' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
