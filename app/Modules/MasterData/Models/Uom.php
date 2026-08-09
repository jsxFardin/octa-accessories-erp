<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $dimension
 */
class Uom extends Model
{
    protected $table = 'uoms';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'dimension',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
        ];
    }
}
