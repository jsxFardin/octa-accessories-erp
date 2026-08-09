<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $packing_list_id
 * @property string $carton_no
 * @property string|null $barcode
 * @property string|null $gross_weight_kg
 * @property string|null $net_weight_kg
 * @property string|null $length_cm
 * @property string|null $width_cm
 * @property string|null $height_cm
 */
class Carton extends Model
{
    protected $table = 'cartons';

    public $timestamps = false;

    protected $fillable = [
        'packing_list_id',
        'carton_no',
        'barcode',
        'gross_weight_kg',
        'net_weight_kg',
        'length_cm',
        'width_cm',
        'height_cm',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'packing_list_id' => 'integer',
            'gross_weight_kg' => 'decimal:3',
            'net_weight_kg' => 'decimal:3',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
        ];
    }
}
