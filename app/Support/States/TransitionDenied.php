<?php

declare(strict_types=1);

namespace App\Support\States;

use App\Support\Text\Plain;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A blocked transition. The message names the rule that blocked it — a supervisor who cannot
 * release a job card must be told which of the four J1 conditions failed, not "forbidden".
 */
class TransitionDenied extends RuntimeException
{
    /**
     * `$document` is a class name ("SalesReturn") and the statuses are keys ("in_transit"); the
     * sentence a person reads uses neither.
     */
    public static function notAllowed(string $document, string $from, string $to): self
    {
        return new self(Plain::refusedChange(Plain::record(Str::snake($document)), $from, $to));
    }

    public static function notPermitted(string $permission): self
    {
        $denied = new self('You do not have permission to '.Plain::permission($permission).'. Ask an administrator to give your role that permission.');
        $denied->permission = $permission;

        return $denied;
    }

    /**
     * The rule reference stays on the exception for logs and tests; it is not part of the
     * sentence, because the sentence is what the screen shows.
     *
     * @param  non-empty-string  $rule
     */
    public static function guard(string $rule, string $message): self
    {
        $denied = new self($message);
        $denied->rule = $rule;

        return $denied;
    }

    /** The rule that blocked the transition, when one did. */
    public ?string $rule = null;

    /** The permission the user lacked, when that is why — by its key, for logs and tests. */
    public ?string $permission = null;
}
