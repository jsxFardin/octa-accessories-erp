<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'lead_time_days',
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

    /** @return HasMany<SupplierQuotationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierQuotationLine::class, 'supplier_quotation_id')->orderBy('line_no');
    }

    /** @return BelongsTo<SupplierRfq, $this> */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(SupplierRfq::class, 'rfq_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
