<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A booking the rules refuse, with a name the shop-floor terminal can translate.
 *
 * The terminal is read in Bangla by people standing at a machine, and the one message that
 * explains why a button did nothing used to reach it as an English sentence with a rule code in
 * front — "J3: output 5200.000 exceeds the 5000.000 handed to this operation". The sentence is
 * still sent, unchanged, for the desk and for anything already reading it. Beside it now travel
 * a stable `code` and the figures the sentence was built from, so the terminal can say the same
 * thing in its own words and in the operator's language.
 *
 * It is a `ValidationException`, so on a desk form it is still an ordinary field error.
 */
final class OperationRefused extends ValidationException
{
    public string $refusalCode = 'refused';

    /** @var array<string, scalar|null> */
    public array $refusalParams = [];

    /** @param array<string, scalar|null> $params */
    public static function because(string $code, string $field, string $message, array $params = []): self
    {
        /** @var self $exception */
        $exception = self::withMessages([$field => $message]);
        $exception->refusalCode = $code;
        $exception->refusalParams = $params;

        return $exception;
    }
}
