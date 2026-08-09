<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        // The PIN is the last four of the badge in this build. Constant-time comparison, and
        // one method to change when a stored PIN hash replaces it.
        if (! hash_equals(substr($cardNo, -4), $pin)) {
            return null;
        }

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
