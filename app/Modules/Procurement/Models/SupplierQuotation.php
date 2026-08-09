<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $rfq_id
 * @property int $supplier_id
 * @property \Illuminate\Support\Carbon $quoted_on
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property int $currency_id
 * @property string $total
 * @property int|null $lead_time_days
 * @property bool $is_selected
 * @property string|null $remarks
 */
class SupplierQuotation extends Model
{
    protected $table = 'supplier_quotations';

    public $timestamps = false;

    protected $fillable = [
        'rfq_id',
        'supplier_id',
        'quoted_on',
        'valid_until',
        'currency_id',
        'total',
        'lead_time_days',
        'is_selected',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rfq_id' => 'integer',
            'supplier_id' => 'integer',
            'quoted_on' => 'date',
            'valid_until' => 'date',
            'currency_id' => 'integer',
            'total' => 'decimal:4',
            'lead_time_days' => 'integer',
            'is_selected' => 'boolean',
        ];
    }
}
