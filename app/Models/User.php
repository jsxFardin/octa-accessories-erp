<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\MasterData\Models\Employee;
use App\Modules\MasterData\Models\FactoryUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property bool $is_active
 * @property string|null $two_factor_secret
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property string $locale
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use Notifiable;
    use SoftDeletes;

    protected $table = 'users';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /** @return HasOne<Employee, $this> */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** @return list<string> */
    public function roleNames(): array
    {
        return $this->roles->pluck('name')->all();
    }

    /**
     * The user's effective permission set — the union of every role's permissions.
     *
     * Cached in Redis and flushed whenever a role or its permissions change
     * (06-rbac §7). Checks always ask for a permission, never a role name.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        /** @var list<string> $names */
        $names = cache()->remember(
            $this->permissionCacheKey(),
            now()->addHours(12),
            fn (): array => $this->roles()
                ->with('permissions:id,name')
                ->get()
                ->flatMap(fn (Role $role): Collection => $role->permissions->pluck('name'))
                ->unique()
                ->values()
                ->all(),
        );

        return $names;
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->hasRole(Role::SUPER_ADMIN)) {
            return true;
        }

        return in_array($permission, $this->permissionNames(), true);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roleNames(), true);
    }

    public function forgetPermissionCache(): void
    {
        cache()->forget($this->permissionCacheKey());
    }

    /**
     * Factory-unit scoping (06-rbac §4). Resolved from the employee record so a user with
     * no employee row — an auditor, the implementer — is unscoped rather than locked out.
     */
    public function factoryUnitId(): ?int
    {
        return $this->employee?->factory_unit_id;
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasOneThrough<FactoryUnit, Employee, $this> */
    public function factoryUnit(): \Illuminate\Database\Eloquent\Relations\HasOneThrough
    {
        return $this->hasOneThrough(
            FactoryUnit::class,
            Employee::class,
            'user_id',
            'id',
            'id',
            'factory_unit_id',
        );
    }

    private function permissionCacheKey(): string
    {
        return "user:{$this->getKey()}:permissions";
    }
}
