<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $number
 * @property int $job_card_id
 * @property int $warehouse_id
 * @property int|null $lot_id
 * @property \Illuminate\Support\Carbon $received_on
 * @property string $qty
 * @property int|null $qc_inspection_id
 * @property string $grade
 * @property string $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class FgReceipt extends Model
{
    use Auditable;

    protected $table = 'fg_receipts';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'job_card_id',
        'warehouse_id',
        'lot_id',
        'received_on',
        'qty',
        'qc_inspection_id',
        'grade',
        'status',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'job_card_id' => 'integer',
            'warehouse_id' => 'integer',
            'lot_id' => 'integer',
            'received_on' => 'date',
            'qty' => 'decimal:6',
            'qc_inspection_id' => 'integer',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }
}
