<?php

declare(strict_types=1);

namespace App\Modules\Trade\Models;

use App\Modules\MasterData\Models\Supplier;
use App\Modules\Procurement\Models\Grn;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A consignment on its way in: what left the supplier, on which vessel, against which credit.
 *
 * The shipment is what the landed cost hangs on. A freight bill is not for a purchase order
 * and not for a goods receipt — it is for a container, which may cover several POs and arrive
 * as several receipts. Modelling it anywhere else is what forces people back into Excel.
 *
 * @property int $id
 * @property string|null $number
 * @property int|null $lc_id
 * @property int $supplier_id
 * @property string|null $invoice_no
 * @property \Illuminate\Support\Carbon|null $invoice_date
 * @property string|null $transport_doc_no
 * @property string $mode
 * @property string|null $carrier
 * @property \Illuminate\Support\Carbon|null $etd
 * @property \Illuminate\Support\Carbon|null $eta
 * @property \Illuminate\Support\Carbon|null $arrived_on
 * @property \Illuminate\Support\Carbon|null $cleared_on
 * @property string|null $bill_of_entry
 * @property \Illuminate\Support\Carbon|null $be_date
 * @property string|null $port_of_loading
 * @property string|null $port_of_discharge
 * @property string|null $incoterm
 * @property int $currency_id
 * @property string $exchange_rate
 * @property string $goods_value
 * @property string $cost_total
 * @property string $allocated_amount
 * @property string $status
 * @property string|null $remarks
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 * @property-read LetterOfCredit|null $letterOfCredit a TT or DP shipment has no credit
 * @property-read Supplier|null $supplier
 */
class ImportShipment extends Model
{
    use Auditable;

    public const MODES = ['sea', 'air', 'road', 'rail', 'courier'];

    public const STATUSES = ['draft', 'in_transit', 'arrived', 'cleared', 'costed', 'closed', 'cancelled'];

    protected $table = 'import_shipments';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number', 'lc_id', 'supplier_id', 'invoice_no', 'invoice_date', 'transport_doc_no',
        'mode', 'carrier', 'etd', 'eta', 'arrived_on', 'cleared_on', 'bill_of_entry', 'be_date',
        'port_of_loading', 'port_of_discharge', 'incoterm', 'currency_id', 'exchange_rate',
        'goods_value', 'cost_total', 'allocated_amount', 'status', 'remarks', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lc_id' => 'integer',
            'supplier_id' => 'integer',
            'invoice_date' => 'date',
            'etd' => 'date',
            'eta' => 'date',
            'arrived_on' => 'date',
            'cleared_on' => 'date',
            'be_date' => 'date',
            'currency_id' => 'integer',
            'exchange_rate' => 'decimal:8',
            'goods_value' => 'decimal:4',
            'cost_total' => 'decimal:4',
            'allocated_amount' => 'decimal:4',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<LetterOfCredit, $this> */
    public function letterOfCredit(): BelongsTo
    {
        return $this->belongsTo(LetterOfCredit::class, 'lc_id');
    }

    /** @return HasMany<ImportCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(ImportCost::class, 'shipment_id');
    }

    /** @return HasMany<Grn, $this> */
    public function grns(): HasMany
    {
        return $this->hasMany(Grn::class, 'import_shipment_id');
    }

    /** Costs that belong in inventory, in base currency (BR-36). */
    public function allocableTotal(): float
    {
        return round((float) $this->costs()->where('is_allocable', true)->sum('base_amount'), 4);
    }
}
