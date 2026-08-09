<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $number
 * @property int $factory_unit_id
 * @property int|null $department_id
 * @property \Illuminate\Support\Carbon $requested_on
 * @property \Illuminate\Support\Carbon|null $required_by
 * @property string $origin
 * @property string $status
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class PurchaseRequisition extends Model
{
    protected $table = 'purchase_requisitions';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number',
        'factory_unit_id',
        'department_id',
        'requested_on',
        'required_by',
        'origin',
        'status',
        'approved_by',
        'approved_at',
        'remarks',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'factory_unit_id' => 'integer',
            'department_id' => 'integer',
            'requested_on' => 'date',
            'required_by' => 'date',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return HasMany<PurchaseRequisitionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class, 'pr_id')->orderBy('line_no');
    }
}
