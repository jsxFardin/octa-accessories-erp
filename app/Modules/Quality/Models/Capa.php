<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $ncr_id
 * @property string $kind
 * @property string|null $root_cause
 * @property string $action
 * @property int|null $responsible_id
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property \Illuminate\Support\Carbon|null $completed_on
 * @property string|null $effectiveness
 * @property string $status
 */
class Capa extends Model
{
    protected $table = 'capas';

    public $timestamps = false;

    protected $fillable = [
        'ncr_id',
        'kind',
        'root_cause',
        'action',
        'responsible_id',
        'due_date',
        'completed_on',
        'effectiveness',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ncr_id' => 'integer',
            'responsible_id' => 'integer',
            'due_date' => 'date',
            'completed_on' => 'date',
        ];
    }
}
