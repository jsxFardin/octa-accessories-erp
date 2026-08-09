<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $supplier_id
 * @property int $item_id
 * @property string|null $supplier_code
 * @property string|null $last_rate
 * @property int|null $currency_id
 * @property int|null $lead_time_days
 * @property string|null $moq
 */
class SupplierItem extends Model
{
    protected $table = 'supplier_items';

    public $timestamps = false;

    protected $fillable = [
        'supplier_id',
        'item_id',
        'supplier_code',
        'last_rate',
        'currency_id',
        'lead_time_days',
        'moq',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'item_id' => 'integer',
            'last_rate' => 'decimal:4',
            'currency_id' => 'integer',
            'lead_time_days' => 'integer',
            'moq' => 'decimal:6',
        ];
    }
}
