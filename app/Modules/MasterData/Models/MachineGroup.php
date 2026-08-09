<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $process_type
 * @property string $output_uom
 */
class MachineGroup extends Model
{
    protected $table = 'machine_groups';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'process_type',
        'output_uom',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
        ];
    }
}
