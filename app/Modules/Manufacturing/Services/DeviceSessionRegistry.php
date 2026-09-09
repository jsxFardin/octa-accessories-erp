<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Badge-and-PIN sessions for the shop floor (07-api-contracts §2, 06-rbac §6).
 *
 * A shift-length token, not a browser session: an operator scans in once and the terminal
 * keeps working through the wifi outages that a loom does not stop for.
 */
class DeviceSessionRegistry
{
    private const TTL_HOURS = 9;

    public function issue(string $cardNo, string $pin, ?string $machineCode = null): ?DeviceSession
    {
        $employee = DB::table('employees')
            ->where('card_no', $cardNo)
            ->where('is_active', true)
            ->first();

        if ($employee === null || $employee->user_id === null) {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->find($employee->user_id);

        if ($user === null || ! $user->is_active) {
            return null;
        }

        // The PIN is a stored hash, not a function of the badge. It used to be the badge's
        // last four characters — printed on the card the operator wears — so reading someone's
        // badge was enough to book output as them (06-rbac §6).
        if ($employee->pin_hash === null || ! Hash::check($pin, (string) $employee->pin_hash)) {
            return null;
        }

        return $this->mint($employee, $user, $machineCode);
    }

    /**
     * A terminal session for someone who is already signed in at a desk.
     *
     * A supervisor opening the terminal from the sidebar has already proved who they are with
     * a password, so asking them for a badge and a PIN they may not have been issued is a
     * second login for no extra assurance. They still need a device session, because the queue
     * is scoped by the factory unit the session carries.
     */
    public function issueForUser(User $user, ?string $machineCode = null): ?DeviceSession
    {
        if (! $user->is_active) {
            return null;
        }

        $employee = DB::table('employees')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        // No employee row means no factory unit, and the queue has nothing to scope to.
        if ($employee === null || $employee->factory_unit_id === null) {
            return null;
        }

        return $this->mint($employee, $user, $machineCode);
    }

    private function mint(object $employee, User $user, ?string $machineCode): DeviceSession
    {
        $token = Str::random(48);

        $session = new DeviceSession(
            token: $token,
            userId: (int) $user->id,
            employeeId: (int) $employee->id,
            employeeName: (string) $employee->name,
            factoryUnitId: (int) $employee->factory_unit_id,
            machineCode: $machineCode,
            expiresAt: now()->addHours(self::TTL_HOURS)->toIso8601String(),
        );

        Cache::put($this->key($token), $session->toArray(), now()->addHours(self::TTL_HOURS));

        return $session;
    }

    public function resolve(string $token): ?DeviceSession
    {
        $payload = Cache::get($this->key($token));

        return $payload === null ? null : DeviceSession::fromArray($payload);
    }

    public function revoke(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'device_session:'.hash('sha256', $token);
    }
}
