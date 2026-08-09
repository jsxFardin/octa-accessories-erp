<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $label
 * @property string $kind
 * @property string $line1
 * @property string|null $line2
 * @property string|null $city
 * @property string|null $district
 * @property string|null $postcode
 * @property string $country
 * @property int $transit_days
 * @property string|null $route_zone
 * @property bool $is_default
 */
class CustomerAddress extends Model
{
    protected $table = 'customer_addresses';

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'label',
        'kind',
        'line1',
        'line2',
        'city',
        'district',
        'postcode',
        'country',
        'transit_days',
        'route_zone',
        'is_default',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'transit_days' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
