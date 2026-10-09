<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the ten kinds of thing the factory makes (01 narrow textile … 10 decoration).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $code_prefix
 * @property int $sort_order
 * @property bool $is_active
 */
class ProductionFamily extends Model
{
    protected $table = 'production_families';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'code_prefix', 'requires_artwork', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['requires_artwork' => 'boolean', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return HasMany<ItemGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(ItemGroup::class)->whereNull('parent_id')->orderBy('sort_order');
    }

    /** @return HasMany<FamilyAttribute, $this> */
    public function attributes(): HasMany
    {
        return $this->hasMany(FamilyAttribute::class)->orderBy('sort_order');
    }
}
