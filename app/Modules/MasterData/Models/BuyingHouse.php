<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $country
 * @property bool $is_active
 */
class BuyingHouse extends Model
{
    protected $table = 'buying_houses';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'country',
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
