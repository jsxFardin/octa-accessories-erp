<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What an expense is for. A short, maintained list rather than free text, because "what did we
 * spend on generator fuel last quarter" is only answerable if the answer was chosen, not typed.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property bool $is_active
 */
class ExpenseCategory extends Model
{
    public const KINDS = ['factory', 'admin', 'selling', 'financial', 'import'];

    protected $table = 'expense_categories';

    public const UPDATED_AT = null;

    protected $fillable = ['code', 'name', 'kind', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'created_at' => 'datetime'];
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
