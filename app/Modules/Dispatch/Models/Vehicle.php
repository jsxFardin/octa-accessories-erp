<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $registration_no
 * @property string $kind
 * @property string|null $capacity_kg
 * @property bool $is_owned
 * @property \Illuminate\Support\Carbon|null $fitness_expiry
 * @property \Illuminate\Support\Carbon|null $tax_expiry
 * @property bool $is_active
 */
class Vehicle extends Model
{
    protected $table = 'vehicles';

    public $timestamps = false;

    protected $fillable = [
        'registration_no',
        'kind',
        'capacity_kg',
        'is_owned',
        'fitness_expiry',
        'tax_expiry',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capacity_kg' => 'decimal:3',
            'is_owned' => 'boolean',
            'fitness_expiry' => 'date',
            'tax_expiry' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
