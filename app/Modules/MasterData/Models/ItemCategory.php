<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $code
 * @property string $name
 * @property string $item_class
 */
class ItemCategory extends Model
{
    protected $table = 'item_categories';

    public $timestamps = false;

    protected $fillable = [
        'parent_id',
        'code',
        'name',
        'item_class',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
        ];
    }
}
