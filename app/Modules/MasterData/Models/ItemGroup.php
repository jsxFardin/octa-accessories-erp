<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group inside a production family, or a sub-group inside a group (`parent_id` set).
 *
 * @property int $id
 * @property int $production_family_id
 * @property int|null $parent_id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 * @property bool $is_active
 */
class ItemGroup extends Model
{
    protected $table = 'item_groups';

    public $timestamps = false;

    protected $fillable = ['production_family_id', 'parent_id', 'code', 'name', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'production_family_id' => 'integer',
            'parent_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ProductionFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductionFamily::class, 'production_family_id');
    }

    /** @return BelongsTo<ItemGroup, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'parent_id');
    }

    /** @return HasMany<ItemGroup, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(ItemGroup::class, 'parent_id')->orderBy('sort_order');
    }
}
