<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property string $code
 * @property string $name
 * @property int $currency_id
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property bool $is_active
 */
class PriceList extends Model
{
    protected $table = 'price_lists';

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'code',
        'name',
        'currency_id',
        'valid_from',
        'valid_to',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'currency_id' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
