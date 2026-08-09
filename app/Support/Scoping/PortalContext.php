<?php

declare(strict_types=1);

namespace App\Support\Scoping;

use RuntimeException;

/**
 * Request-scoped holder for the portal customer id. Bound by EnsurePortalCustomer and read
 * by the BelongsToCustomer global scope. A singleton per request, never per process.
 */
class PortalContext
{
    private ?int $customerId = null;

    public function bind(int $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function isActive(): bool
    {
        return $this->customerId !== null;
    }

    public function customerId(): int
    {
        if ($this->customerId === null) {
            throw new RuntimeException('No portal customer bound to this request.');
        }

        return $this->customerId;
    }
}
