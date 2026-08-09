<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $qc_inspection_id
 * @property int $defect_id
 * @property int $qty
 * @property string|null $remarks
 */
class QcInspectionDefect extends Model
{
    protected $table = 'qc_inspection_defects';

    public $timestamps = false;

    protected $fillable = [
        'qc_inspection_id',
        'defect_id',
        'qty',
        'remarks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qc_inspection_id' => 'integer',
            'defect_id' => 'integer',
            'qty' => 'integer',
        ];
    }
}
