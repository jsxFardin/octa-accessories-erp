<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $number
 * @property int|null $lot_id
 * @property int|null $job_card_id
 * @property int|null $product_id
 * @property int|null $customer_id
 * @property \Illuminate\Support\Carbon $tested_on
 * @property int|null $technician_id
 * @property string $overall_result
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $issued_at
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class TestReport extends Model
{
    use Auditable;

    protected $table = 'test_reports';

    public const UPDATED_AT = null;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'number',
        'lot_id',
        'job_card_id',
        'product_id',
        'customer_id',
        'tested_on',
        'technician_id',
        'overall_result',
        'status',
        'issued_at',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lot_id' => 'integer',
            'job_card_id' => 'integer',
            'product_id' => 'integer',
            'customer_id' => 'integer',
            'tested_on' => 'date',
            'technician_id' => 'integer',
            'issued_at' => 'datetime',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return HasMany<TestReportLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(TestReportLine::class, 'test_report_id');
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Customer::class, 'customer_id');
    }

    /** @return BelongsTo<\App\Modules\Inventory\Models\StockLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Inventory\Models\StockLot::class, 'lot_id');
    }

    /** @return BelongsTo<\App\Models\User, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'technician_id');
    }

    /** @return BelongsTo<\App\Models\User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
