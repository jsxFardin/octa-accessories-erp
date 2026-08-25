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

    /** The address on one line, as a challan or a carton label prints it. */
    public function oneLine(): string
    {
        return collect([$this->line1, $this->line2, $this->city, $this->district, $this->postcode, $this->country])
            ->filter(fn (?string $part): bool => filled($part))
            ->join(', ');
    }

    /**
     * The address a delivery to this customer goes to when nothing more specific was chosen:
     * their default delivery address, or the only one they have.
     *
     * A sales order raised from a quotation names no address — a quotation is a price, not a
     * shipment — and until this existed the whole chain below it (packing list → challan)
     * inherited that emptiness and arrived at dispatch with nowhere to go.
     */
    public static function defaultDeliveryFor(int $customerId): ?self
    {
        return self::query()
            ->where('customer_id', $customerId)
            ->whereIn('kind', ['delivery', 'both'])
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
