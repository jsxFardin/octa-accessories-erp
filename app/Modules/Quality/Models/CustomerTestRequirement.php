<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $customer_id
 * @property int|null $product_id
 * @property int $lab_test_id
 * @property string $pass_value
 * @property bool $is_mandatory
 * @property int|null $product_key
 */
class CustomerTestRequirement extends Model
{
    protected $table = 'customer_test_requirements';

    public $timestamps = false;

    // `product_key` are STORED generated columns: MySQL writes them, the application never
    // does, and they are absent from $fillable for that reason (02-database-schema §5.1).

    protected $fillable = [
        'customer_id',
        'product_id',
        'lab_test_id',
        'pass_value',
        'is_mandatory',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'product_id' => 'integer',
            'lab_test_id' => 'integer',
            'is_mandatory' => 'boolean',
        ];
    }
}
