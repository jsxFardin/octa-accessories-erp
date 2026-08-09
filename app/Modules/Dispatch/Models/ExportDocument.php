<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $delivery_challan_id
 * @property int|null $sales_order_id
 * @property string $doc_type
 * @property string $doc_no
 * @property \Illuminate\Support\Carbon|null $doc_date
 * @property string|null $file_path
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 */
class ExportDocument extends Model
{
    protected $table = 'export_documents';

    public const UPDATED_AT = null;

    protected $fillable = [
        'delivery_challan_id',
        'sales_order_id',
        'doc_type',
        'doc_no',
        'doc_date',
        'file_path',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivery_challan_id' => 'integer',
            'sales_order_id' => 'integer',
            'doc_date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
