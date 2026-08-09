<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $certification_id
 * @property string|null $product_type
 * @property int|null $item_category_id
 * @property string $min_claim_pct
 * @property string $labelled_claim_pct
 * @property string $max_conversion_factor
 */
class CertificationScope extends Model
{
    protected $table = 'certification_scopes';

    public $timestamps = false;

    protected $fillable = [
        'certification_id',
        'product_type',
        'item_category_id',
        'min_claim_pct',
        'labelled_claim_pct',
        'max_conversion_factor',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'certification_id' => 'integer',
            'item_category_id' => 'integer',
            'min_claim_pct' => 'decimal:4',
            'labelled_claim_pct' => 'decimal:4',
            'max_conversion_factor' => 'decimal:4',
        ];
    }
}
