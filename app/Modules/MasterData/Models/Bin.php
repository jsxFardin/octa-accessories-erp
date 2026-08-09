<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $warehouse_id
 * @property string $code
 * @property string|null $description
 */
class Bin extends Model
{
    protected $table = 'bins';

    public $timestamps = false;

    protected $fillable = [
        'warehouse_id',
        'code',
        'description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'warehouse_id' => 'integer',
        ];
    }
}
