<?php

declare(strict_types=1);

namespace App\Modules\Trade\Models;

use App\Modules\MasterData\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of what a shipment cost beyond the goods.
 *
 * `is_allocable` is the only field with an opinion in it: freight, duty and the C&F agent's
 * bill belong in the cost of a kilo of yarn; a demurrage penalty and an LC amendment charge
 * are period costs, and burying them in inventory hides the very thing somebody should be
 * angry about.
 *
 * @property int $id
 * @property int $shipment_id
 * @property string $cost_type
 * @property string|null $description
 * @property int|null $supplier_id
 * @property int|null $expense_id
 * @property string|null $reference_no
 * @property \Illuminate\Support\Carbon $incurred_on
 * @property int $currency_id
 * @property string $exchange_rate
 * @property string $amount
 * @property string $base_amount
 * @property bool $is_allocable
 */
class ImportCost extends Model
{
    /** Costs that normally sit in inventory, offered first on the form. */
    public const ALLOCABLE_TYPES = ['freight', 'insurance', 'duty', 'advance_income_tax', 'c_and_f', 'port', 'inland_transport', 'inspection'];

    public const TYPES = [
        'freight', 'insurance', 'duty', 'vat', 'advance_income_tax', 'c_and_f', 'port',
        'inland_transport', 'bank_charge', 'lc_commission', 'inspection', 'demurrage', 'other',
    ];

    protected $table = 'import_costs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'shipment_id', 'cost_type', 'description', 'supplier_id', 'expense_id', 'reference_no',
        'incurred_on', 'currency_id', 'exchange_rate', 'amount', 'base_amount', 'is_allocable', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'shipment_id' => 'integer',
            'supplier_id' => 'integer',
            'expense_id' => 'integer',
            'incurred_on' => 'date',
            'currency_id' => 'integer',
            'exchange_rate' => 'decimal:8',
            'amount' => 'decimal:4',
            'base_amount' => 'decimal:4',
            'is_allocable' => 'boolean',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<ImportShipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ImportShipment::class, 'shipment_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
