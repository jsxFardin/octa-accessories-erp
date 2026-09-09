<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $lot_no
 * @property int|null $item_id
 * @property int|null $product_id
 * @property string $kind
 * @property int $warehouse_id
 * @property int|null $bin_id
 * @property int $uom_id
 * @property string $received_qty
 * @property string $balance_qty
 * @property string $unit_cost
 * @property int|null $grn_line_id
 * @property int|null $job_card_id
 * @property int|null $parent_lot_id
 * @property string|null $supplier_batch_no
 * @property string|null $shade_code
 * @property string|null $roll_length_m
 * @property \Illuminate\Support\Carbon $received_on
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property string|null $cert_scheme
 * @property string $cert_claim_pct
 * @property string|null $cert_document_no
 * @property string $status
 * @property string|null $barcode
 * @property \Illuminate\Support\Carbon $created_at
 */
class StockLot extends Model
{
    protected $table = 'stock_lots';

    public const UPDATED_AT = null;

    protected $fillable = [
        'lot_no',
        'item_id',
        'product_id',
        'kind',
        'warehouse_id',
        'bin_id',
        'uom_id',
        'received_qty',
        'balance_qty',
        'unit_cost',
        'grn_line_id',
        'job_card_id',
        'parent_lot_id',
        'supplier_batch_no',
        'shade_code',
        'roll_length_m',
        'received_on',
        'expiry_date',
        'cert_scheme',
        'cert_claim_pct',
        'cert_document_no',
        'status',
        'barcode',
    ];

    /**
     * Every lot is born with a barcode.
     *
     * G6 — "any carton → its lots → its GRNs, in ≤ 3 clicks" — and the overview's claim that
     * "every physical unit carries a barcode and a lot number" both rest on this column, which
     * nothing ever populated. Cartons got one (`CTN-{list}-{n}`); lots got NULL on every path,
     * and the transfer machine wrote NULL explicitly. `stock_lot.print_barcode` is a permission
     * with no route behind it, so a store keeper could not print a label for a roll even in
     * principle: there was nothing on the row to print.
     *
     * Derived from the lot number rather than invented separately. The two carry the same
     * identity, both columns are UNIQUE, and a scanner reading a label should land on the same
     * lot a person reading it aloud would. An explicitly supplied barcode is left alone — a
     * supplier's own label is more useful than one of ours over the top of it.
     */
    protected static function booted(): void
    {
        static::creating(function (self $lot): void {
            if (blank($lot->barcode) && filled($lot->lot_no)) {
                $lot->barcode = $lot->lot_no;
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'product_id' => 'integer',
            'warehouse_id' => 'integer',
            'bin_id' => 'integer',
            'uom_id' => 'integer',
            'received_qty' => 'decimal:6',
            'balance_qty' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'grn_line_id' => 'integer',
            'job_card_id' => 'integer',
            'parent_lot_id' => 'integer',
            'roll_length_m' => 'decimal:6',
            'received_on' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'cert_claim_pct' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Item::class);
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Warehouse::class);
    }

    /** @return BelongsTo<\App\Modules\Product\Models\Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Models\Product::class);
    }

    /** @return HasMany<StockLedgerEntry, $this> */
    public function ledger(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class, 'lot_id')->orderBy('occurred_at');
    }

    /** I3 — the authoritative balance is the ledger sum; balance_qty is a rebuildable cache. */
    public function ledgerBalance(): float
    {
        return (float) $this->ledger()->sum('qty');
    }
}
