<?php

declare(strict_types=1);

namespace App\Support\States;

use RuntimeException;

/**
 * A blocked transition. The message names the rule that blocked it — a supervisor who cannot
 * release a job card must be told which of the four J1 conditions failed, not "forbidden".
 */
class TransitionDenied extends RuntimeException
{
    public static function notAllowed(string $document, string $from, string $to): self
    {
        return new self("{$document} cannot move from [{$from}] to [{$to}].");
    }

    public static function notPermitted(string $permission): self
    {
        return new self("You do not have the [{$permission}] permission.");
    }

    /** @param non-empty-string $rule */
    public static function guard(string $rule, string $message): self
    {
        return new self("{$message} ({$rule})");
    }
}
