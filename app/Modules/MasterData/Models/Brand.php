<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
class Brand extends Model
{
    protected $table = 'brands';

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'code',
        'name',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
