<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $country
 * @property string|null $address
 * @property string|null $email
 * @property string|null $phone
 * @property int|null $currency_id
 * @property int|null $payment_term_id
 * @property int $lead_time_days
 * @property bool $is_approved
 * @property string|null $rating
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Supplier extends Model
{
    use SoftDeletes;

    protected $table = 'suppliers';

    public const UPDATED_AT = null;

    protected $fillable = [
        'code',
        'name',
        'country',
        'address',
        'email',
        'phone',
        'currency_id',
        'payment_term_id',
        'lead_time_days',
        'is_approved',
        'rating',
        'is_active',
        'deleted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'currency_id' => 'integer',
            'payment_term_id' => 'integer',
            'lead_time_days' => 'integer',
            'is_approved' => 'boolean',
            'rating' => 'decimal:2',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return HasMany<SupplierContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    /** @return HasMany<SupplierItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierItem::class);
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
