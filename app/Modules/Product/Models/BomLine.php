<?php

declare(strict_types=1);

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bom_id
 * @property int $item_id
 * @property int $uom_id
 * @property string $qty_per_base
 * @property string $wastage_pct
 * @property int|null $colour_index
 * @property bool $is_optional
 * @property string|null $formula_ref
 * @property string|null $notes
 * @property int|null $colour_key
 */
class BomLine extends Model
{
    protected $table = 'bom_lines';

    public $timestamps = false;

    // `colour_key` are STORED generated columns: MySQL writes them, the application never
    // does, and they are absent from $fillable for that reason (02-database-schema §5.1).

    protected $fillable = [
        'bom_id',
        'item_id',
        'uom_id',
        'qty_per_base',
        'wastage_pct',
        'colour_index',
        'is_optional',
        'formula_ref',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bom_id' => 'integer',
            'item_id' => 'integer',
            'uom_id' => 'integer',
            'qty_per_base' => 'decimal:6',
            'wastage_pct' => 'decimal:4',
            'colour_index' => 'integer',
            'is_optional' => 'boolean',
        ];
    }

    /** @return BelongsTo<Bom, $this> */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Item::class);
    }

    /** @return BelongsTo<\App\Modules\MasterData\Models\Uom, $this> */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\MasterData\Models\Uom::class);
    }
}
