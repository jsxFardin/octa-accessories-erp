<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $factory_unit_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property bool $is_nettable
 * @property bool $is_active
 */
class Warehouse extends Model
{
    protected $table = 'warehouses';

    public $timestamps = false;

    protected $fillable = [
        'factory_unit_id',
        'code',
        'name',
        'kind',
        'is_nettable',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'factory_unit_id' => 'integer',
            'is_nettable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<FactoryUnit, $this> */
    public function factoryUnit(): BelongsTo
    {
        return $this->belongsTo(FactoryUnit::class);
    }

    /** @return HasMany<Bin, $this> */
    public function bins(): HasMany
    {
        return $this->hasMany(Bin::class);
    }

    /** BR-24 — scrap and transit stock exists, but MRP may not plan against it. */
    public function countsTowardAvailability(): bool
    {
        return (bool) $this->is_nettable;
    }
}
