<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\MasterData\Models\Supplier;
use App\Modules\Trade\Models\BankAccount;
use App\Modules\Trade\Models\ImportShipment;
use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A factory or office expense: generator fuel, courier, port charge, an entertainment bill.
 *
 * The document is small on purpose — one amount, one category, one payee — because the
 * alternative is what it replaces: a cash book nobody reconciles. What it does carry is the
 * approval, which is the only reason a spend record is worth keeping.
 *
 * An expense may name an `import_shipment_id`. That is a reporting link, not a costing one:
 * what lands in a lot's cost is the matching `import_costs` row, and the two are joined
 * through `import_costs.expense_id` so nothing is counted twice.
 *
 * @property int $id
 * @property string|null $number
 * @property \Illuminate\Support\Carbon $expense_date
 * @property int $expense_category_id
 * @property int|null $factory_unit_id
 * @property int|null $department_id
 * @property int|null $supplier_id
 * @property int|null $import_shipment_id
 * @property string $payee
 * @property string|null $description
 * @property int $currency_id
 * @property string $exchange_rate
 * @property string $amount
 * @property string $tax_amount
 * @property string $total
 * @property string $method
 * @property int|null $bank_account_id
 * @property string|null $reference_no
 * @property string $status
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $paid_on
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class Expense extends Model
{
    use Auditable;

    public const METHODS = ['cash', 'cheque', 'bank_transfer', 'card', 'adjustment'];

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'paid', 'cancelled'];

    protected $table = 'expenses';

    public const UPDATED_AT = null;

    protected $fillable = [
        'number', 'expense_date', 'expense_category_id', 'factory_unit_id', 'department_id',
        'supplier_id', 'import_shipment_id', 'payee', 'description', 'currency_id',
        'exchange_rate', 'amount', 'tax_amount', 'total', 'method', 'bank_account_id',
        'reference_no', 'status', 'approved_by', 'approved_at', 'paid_on', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expense_date' => 'date:Y-m-d',
            'expense_category_id' => 'integer',
            'factory_unit_id' => 'integer',
            'department_id' => 'integer',
            'supplier_id' => 'integer',
            'import_shipment_id' => 'integer',
            'currency_id' => 'integer',
            'exchange_rate' => 'decimal:8',
            'amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'bank_account_id' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'paid_on' => 'date:Y-m-d',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return BelongsTo<ImportShipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ImportShipment::class, 'import_shipment_id');
    }

    /** In base currency, which is what any total across currencies has to be summed in. */
    public function baseTotal(): float
    {
        return round((float) $this->total * (float) $this->exchange_rate, 4);
    }
}
