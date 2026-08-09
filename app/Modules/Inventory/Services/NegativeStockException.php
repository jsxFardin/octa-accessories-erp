<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use RuntimeException;

/**
 * BR-38 / I4 — negative stock is prohibited, and there is no setting that allows it.
 *
 * The message names the lot and the shortfall, because the store keeper's next action is to
 * find the missing quantity, not to read a stack trace.
 */
class NegativeStockException extends RuntimeException
{
    public static function forLot(string $lotNo, float $balance, float $requested): self
    {
        return new self(sprintf(
            'Lot %s holds %s but %s was requested — %s short. Stock may not go negative (BR-38).',
            $lotNo,
            rtrim(rtrim(number_format($balance, 6, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($requested, 6, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($requested - $balance, 6, '.', ''), '0'), '.'),
        ));
    }
}
