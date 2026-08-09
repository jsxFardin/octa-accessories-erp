<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Roles are bundles of permissions, editable by an admin without a deploy (06-rbac §2).
 * Code never checks a role name — the only legitimate reference is SUPER_ADMIN, which is
 * the escape hatch that keeps the implementer out of a lockout.
 *
 * @property int $id
 * @property string $name
 * @property string $label
 * @property bool $is_system
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Permission> $permissions
 * @property-read int|null $users_count
 */
class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    public $timestamps = false;

    protected $fillable = ['name', 'label', 'is_system'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }
}
