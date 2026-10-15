<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $export_shipment_id
 * @property int|null $delivery_challan_id
 * @property int|null $sales_order_id
 * @property string $doc_type
 * @property string $doc_no
 * @property \Illuminate\Support\Carbon|null $doc_date
 * @property string|null $file_path
 * @property string $status
 * @property string|null $remarks
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class ExportDocument extends Model
{
    protected $table = 'export_documents';

    public const UPDATED_AT = null;

    /** DF-6 — drafted by the system, issued by hand or an authority, submitted to the bank. */
    public const STATUSES = ['drafted', 'issued', 'submitted'];

    protected $fillable = [
        'export_shipment_id',
        'delivery_challan_id',
        'sales_order_id',
        'doc_type',
        'doc_no',
        'doc_date',
        'file_path',
        'status',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'export_shipment_id' => 'integer',
            'delivery_challan_id' => 'integer',
            'created_by' => 'integer',
            'sales_order_id' => 'integer',
            'doc_date' => 'date:Y-m-d',
            'created_at' => 'datetime',
        ];
    }
}
