<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $factory_unit_id
 * @property int|null $department_id
 * @property string $code
 * @property string $name
 * @property string|null $designation
 * @property string|null $phone
 * @property string|null $card_no
 * @property string|null $skill_grade
 * @property \Illuminate\Support\Carbon|null $joined_on
 * @property bool $is_active
 */
class Employee extends Model
{
    protected $table = 'employees';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'factory_unit_id',
        'department_id',
        'code',
        'name',
        'designation',
        'phone',
        'card_no',
        'skill_grade',
        'joined_on',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'factory_unit_id' => 'integer',
            'department_id' => 'integer',
            'joined_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<\App\Models\User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    /** @return BelongsTo<FactoryUnit, $this> */
    public function factoryUnit(): BelongsTo
    {
        return $this->belongsTo(FactoryUnit::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
