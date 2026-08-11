<?php

declare(strict_types=1);

namespace App\Modules\Trade\Models;

use App\Modules\MasterData\Models\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank account the factory holds. Named here rather than typed into each document: an LC is
 * opened against one, a payment leaves one, and an expense is paid from one.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $bank_name
 * @property string|null $branch
 * @property string|null $account_no
 * @property string|null $swift_code
 * @property int $currency_id
 * @property string $kind
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $created_at
 */
class BankAccount extends Model
{
    protected $table = 'bank_accounts';

    public const UPDATED_AT = null;

    protected $fillable = [
        'code', 'name', 'bank_name', 'branch', 'account_no', 'swift_code',
        'currency_id', 'kind', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'currency_id' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
