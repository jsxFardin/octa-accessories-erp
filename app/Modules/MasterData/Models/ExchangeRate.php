<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $currency_id
 * @property \Illuminate\Support\Carbon $effective_on
 * @property string $rate_to_base
 */
class ExchangeRate extends Model
{
    protected $table = 'exchange_rates';

    public $timestamps = false;

    protected $fillable = [
        'currency_id',
        'effective_on',
        'rate_to_base',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'currency_id' => 'integer',
            'effective_on' => 'date',
            'rate_to_base' => 'decimal:8',
        ];
    }
}
