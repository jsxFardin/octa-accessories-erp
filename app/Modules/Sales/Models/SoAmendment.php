<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sales_order_id
 * @property int $revision_no
 * @property string $changed_field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string $reason
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class SoAmendment extends Model
{
    protected $table = 'so_amendments';

    public const UPDATED_AT = null;

    protected $fillable = [
        'sales_order_id',
        'revision_no',
        'changed_field',
        'old_value',
        'new_value',
        'reason',
        'approved_by',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sales_order_id' => 'integer',
            'revision_no' => 'integer',
            'approved_by' => 'integer',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }
}
