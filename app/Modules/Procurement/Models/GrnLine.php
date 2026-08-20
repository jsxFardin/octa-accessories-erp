<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $grn_id
 * @property int $line_no
 * @property int|null $po_line_id
 * @property int $item_id
 * @property int $uom_id
 * @property string $received_qty
 * @property string $accepted_qty
 * @property string $rejected_qty
 * @property string $rate
 * @property string $landed_rate
 * @property string|null $supplier_batch_no
 * @property string|null $shade_code
 * @property \Illuminate\Support\Carbon|null $manufactured_on
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property string|null $cert_scheme
 * @property string $cert_claim_pct
 * @property string|null $cert_document_no
 */
class GrnLine extends Model
{
    protected $table = 'grn_lines';

    public $timestamps = false;

    protected $fillable = [
        'grn_id',
        'line_no',
        'po_line_id',
        'item_id',
        'uom_id',
        'received_qty',
        'accepted_qty',
        'rejected_qty',
        'rate',
        'landed_rate',
        'supplier_batch_no',
        'shade_code',
        'manufactured_on',
        'expiry_date',
        'cert_scheme',
        'cert_claim_pct',
        'cert_document_no',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'grn_id' => 'integer',
            'line_no' => 'integer',
            'po_line_id' => 'integer',
            'item_id' => 'integer',
            'uom_id' => 'integer',
            'received_qty' => 'decimal:6',
            'accepted_qty' => 'decimal:6',
            'rejected_qty' => 'decimal:6',
            'rate' => 'decimal:4',
            'landed_rate' => 'decimal:4',
            'manufactured_on' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'cert_claim_pct' => 'decimal:4',
        ];
    }
}
