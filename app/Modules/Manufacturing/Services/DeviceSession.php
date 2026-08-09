<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Models\User;

final readonly class DeviceSession
{
    public function __construct(
        public string $token,
        public int $userId,
        public int $employeeId,
        public string $employeeName,
        public int $factoryUnitId,
        public ?string $machineCode,
        public string $expiresAt,
    ) {}

    public function user(): ?User
    {
        return User::query()->find($this->userId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'user_id' => $this->userId,
            'employee_id' => $this->employeeId,
            'employee_name' => $this->employeeName,
            'factory_unit_id' => $this->factoryUnitId,
            'machine_code' => $this->machineCode,
            'expires_at' => $this->expiresAt,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): ?self
    {
        if ($payload === []) {
            return null;
        }

        return new self(
            token: (string) $payload['token'],
            userId: (int) $payload['user_id'],
            employeeId: (int) $payload['employee_id'],
            employeeName: (string) $payload['employee_name'],
            factoryUnitId: (int) $payload['factory_unit_id'],
            machineCode: $payload['machine_code'] ?? null,
            expiresAt: (string) $payload['expires_at'],
        );
    }
}
