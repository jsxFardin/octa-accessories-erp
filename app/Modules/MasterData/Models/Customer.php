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
 * @property string $kind
 * @property int|null $buying_house_id
 * @property int|null $agent_id
 * @property int|null $currency_id
 * @property int|null $payment_term_id
 * @property string $credit_limit
 * @property string $min_order_value
 * @property string $over_tolerance_pct
 * @property string $under_tolerance_pct
 * @property string|null $bin_no
 * @property string|null $tin_no
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Customer extends Model
{
    use SoftDeletes;

    protected $table = 'customers';

    public const UPDATED_AT = null;

    protected $fillable = [
        'code',
        'name',
        'kind',
        'buying_house_id',
        'agent_id',
        'currency_id',
        'payment_term_id',
        'credit_limit',
        'min_order_value',
        'over_tolerance_pct',
        'under_tolerance_pct',
        'bin_no',
        'tin_no',
        'email',
        'phone',
        'is_active',
        'deleted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'buying_house_id' => 'integer',
            'agent_id' => 'integer',
            'currency_id' => 'integer',
            'payment_term_id' => 'integer',
            'credit_limit' => 'decimal:4',
            'min_order_value' => 'decimal:4',
            'over_tolerance_pct' => 'decimal:4',
            'under_tolerance_pct' => 'decimal:4',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return HasMany<CustomerContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    /** @return HasMany<CustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
