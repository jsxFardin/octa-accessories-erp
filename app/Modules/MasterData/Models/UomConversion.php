<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $item_id
 * @property int $from_uom_id
 * @property int $to_uom_id
 * @property string $factor
 * @property int|null $item_key
 */
class UomConversion extends Model
{
    protected $table = 'uom_conversions';

    public $timestamps = false;

    // `item_key` are STORED generated columns: MySQL writes them, the application never
    // does, and they are absent from $fillable for that reason (02-database-schema §5.1).

    protected $fillable = [
        'item_id',
        'from_uom_id',
        'to_uom_id',
        'factor',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'from_uom_id' => 'integer',
            'to_uom_id' => 'integer',
            'factor' => 'decimal:8',
        ];
    }
}
