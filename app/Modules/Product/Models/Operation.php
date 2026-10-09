<?php

declare(strict_types=1);

namespace App\Modules\Product\Models;

use App\Modules\MasterData\Models\MachineGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The operation master (spec §2). A shared process — dyeing, printing, plating, cutting — is
 * one row, and every family routing that runs it points here.
 */
class Operation extends Model
{
    protected $table = 'operations';

    public $timestamps = false;

    protected $fillable = [
        'code', 'name', 'process_type', 'machine_group_id', 'output_uom',
        'is_shared', 'requires_qc', 'sort_order', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'machine_group_id' => 'integer',
            'is_shared' => 'boolean',
            'requires_qc' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<MachineGroup, $this> */
    public function machineGroup(): BelongsTo
    {
        return $this->belongsTo(MachineGroup::class, 'machine_group_id');
    }
}
