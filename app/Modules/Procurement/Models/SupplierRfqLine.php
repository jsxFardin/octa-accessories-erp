<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $rfq_id
 * @property int $line_no
 * @property int $item_id
 * @property string $qty
 * @property int $uom_id
 */
class SupplierRfqLine extends Model
{
    protected $table = 'supplier_rfq_lines';

    public $timestamps = false;

    protected $fillable = [
        'rfq_id',
        'line_no',
        'item_id',
        'qty',
        'uom_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rfq_id' => 'integer',
            'line_no' => 'integer',
            'item_id' => 'integer',
            'qty' => 'decimal:6',
            'uom_id' => 'integer',
        ];
    }
}
