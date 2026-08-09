<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $net_days
 * @property bool $is_lc
 * @property bool $is_advance
 */
class PaymentTerm extends Model
{
    protected $table = 'payment_terms';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'net_days',
        'is_lc',
        'is_advance',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'net_days' => 'integer',
            'is_lc' => 'boolean',
            'is_advance' => 'boolean',
        ];
    }
}
