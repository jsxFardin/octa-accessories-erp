<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Validation\ValidationException;

/**
 * Refusing an action in a way the screen can show.
 *
 * `abort(422, $message)` is the right shape for the device API and the wrong one for a desk
 * controller: 422 is not a status the application renders an error page for, so on an Inertia
 * visit the message arrived as a bare response and the user was told nothing. A validation
 * exception travels back as an ordinary form error instead — the page stays where it is, the
 * message is shown, and whatever was typed is kept.
 */
trait RefusesActions
{
    /**
     * @param  string  $field  the form field the refusal belongs to, when there is one
     *
     * @throws ValidationException
     */
    protected function refuseUnless(bool $allowed, string $message, string $field = 'action'): void
    {
        if (! $allowed) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /** @throws ValidationException */
    protected function refuseIf(bool $forbidden, string $message, string $field = 'action'): void
    {
        $this->refuseUnless(! $forbidden, $message, $field);
    }

    /** `pending_approval` → "pending approval", for a status named inside a sentence. */
    protected function statusWords(string $status): string
    {
        return str_replace('_', ' ', $status);
    }
}
