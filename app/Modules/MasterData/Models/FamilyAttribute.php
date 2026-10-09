<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A specification attribute every item of a production family carries — diameter for a cord,
 * chain type for a zipper. Values are stored in `items.attributes` under `attr_key`.
 *
 * @property int $id
 * @property int $production_family_id
 * @property string $attr_key
 * @property string $label
 * @property string $data_type
 * @property string|null $unit
 * @property list<string>|null $options
 * @property bool $is_required
 * @property int $sort_order
 * @property bool $is_active
 */
class FamilyAttribute extends Model
{
    protected $table = 'family_attributes';

    public $timestamps = false;

    public const TYPES = ['text', 'number', 'select', 'boolean'];

    protected $fillable = ['production_family_id', 'attr_key', 'label', 'data_type', 'unit', 'options', 'is_required', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'production_family_id' => 'integer',
            'options' => 'array',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ProductionFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductionFamily::class, 'production_family_id');
    }
}
