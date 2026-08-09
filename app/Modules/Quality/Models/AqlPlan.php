<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $standard
 * @property string $inspection_level
 * @property string $aql_value
 * @property int $lot_size_from
 * @property int $lot_size_to
 * @property int $sample_size
 * @property int $accept_number
 * @property int $reject_number
 */
class AqlPlan extends Model
{
    protected $table = 'aql_plans';

    public $timestamps = false;

    protected $fillable = [
        'standard',
        'inspection_level',
        'aql_value',
        'lot_size_from',
        'lot_size_to',
        'sample_size',
        'accept_number',
        'reject_number',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'aql_value' => 'decimal:2',
            'lot_size_from' => 'integer',
            'lot_size_to' => 'integer',
            'sample_size' => 'integer',
            'accept_number' => 'integer',
            'reject_number' => 'integer',
        ];
    }
}
