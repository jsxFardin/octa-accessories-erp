<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $certification_id
 * @property string $scheme
 * @property string $direction
 * @property int $period_year
 * @property int $period_month
 * @property int|null $grn_line_id
 * @property int|null $lot_id
 * @property int|null $job_card_id
 * @property int|null $packing_list_id
 * @property int|null $item_id
 * @property int|null $product_id
 * @property string $qty
 * @property int|null $uom_id
 * @property string $claim_pct
 * @property string|null $document_no
 * @property bool $is_locked
 * @property \Illuminate\Support\Carbon $created_at
 * @property int|null $created_by
 */
class CocTransaction extends Model
{
    protected $table = 'coc_transactions';

    public const UPDATED_AT = null;

    protected $fillable = [
        'certification_id',
        'scheme',
        'direction',
        'period_year',
        'period_month',
        'grn_line_id',
        'lot_id',
        'job_card_id',
        'packing_list_id',
        'item_id',
        'product_id',
        'qty',
        'uom_id',
        'claim_pct',
        'document_no',
        'is_locked',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'certification_id' => 'integer',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'grn_line_id' => 'integer',
            'lot_id' => 'integer',
            'job_card_id' => 'integer',
            'packing_list_id' => 'integer',
            'item_id' => 'integer',
            'product_id' => 'integer',
            'qty' => 'decimal:6',
            'uom_id' => 'integer',
            'claim_pct' => 'decimal:4',
            'is_locked' => 'boolean',
            'created_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }
}
