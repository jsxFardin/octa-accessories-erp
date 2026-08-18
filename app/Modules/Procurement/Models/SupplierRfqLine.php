<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $rfq_id
 * @property int $line_no
 * @property int $item_id
 * @property string $qty
 * @property int $uom_id
 */
class SupplierRfqLine extends Model
{
    protected $table = 'supplier_rfq_lines';

    public $timestamps = false;

    protected $fillable = [
        'rfq_id',
        'line_no',
        'item_id',
        'qty',
        'uom_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rfq_id' => 'integer',
            'line_no' => 'integer',
            'item_id' => 'integer',
            'qty' => 'decimal:6',
            'uom_id' => 'integer',
        ];
    }

    /** @return BelongsTo<SupplierRfq, $this> */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(SupplierRfq::class, 'rfq_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /** @return BelongsTo<Uom, $this> */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'uom_id');
    }
}
