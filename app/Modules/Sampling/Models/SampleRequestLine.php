<?php

declare(strict_types=1);

namespace App\Modules\Sampling\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sample_request_id
 * @property int $line_no
 * @property int|null $product_id
 * @property int|null $product_spec_id
 * @property int|null $artwork_version_id
 * @property string $description
 * @property string $qty
 * @property string|null $colourway
 * @property string $status
 */
class SampleRequestLine extends Model
{
    protected $table = 'sample_request_lines';

    public $timestamps = false;

    protected $fillable = [
        'sample_request_id',
        'line_no',
        'product_id',
        'product_spec_id',
        'artwork_version_id',
        'description',
        'qty',
        'colourway',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sample_request_id' => 'integer',
            'line_no' => 'integer',
            'product_id' => 'integer',
            'product_spec_id' => 'integer',
            'artwork_version_id' => 'integer',
            'qty' => 'decimal:6',
        ];
    }
}
