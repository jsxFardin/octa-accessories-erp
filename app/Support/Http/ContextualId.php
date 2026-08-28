<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * The record id behind a contextual query parameter — `?inquiry=`, `?po=`, `?job_card=` — or
 * null when the parameter does not name one.
 *
 * `$request->integer('inquiry')` casts with PHP's rules, which are forgiving in a way a URL
 * parameter should not be: `"1.5"`, `"1 OR 1=1"` and `"1'"` all become the integer `1`. None of
 * those is a privilege escalation — the same user could pass `1` outright — but each is a
 * request for a record that does not exist being silently answered with a different record that
 * does. A handoff that quietly preselects something other than what the URL named is the same
 * class of defect as one that preselects nothing and says nothing: the screen stops describing
 * the request that produced it.
 *
 * So an id is accepted only when the raw parameter is exactly how that id is written: digits,
 * no sign, no decimal point, no whitespace, no trailing anything. Everything else — missing,
 * empty, zero, negative, fractional, non-numeric, or numeric with a tail — resolves to null,
 * and the caller's existing "resolve to nothing, say so" path takes over.
 *
 * This decides only whether the parameter *names* an id. Whether the viewer may see that record,
 * and whether the record is in a state the handoff allows, stay with the caller: those are
 * domain questions and they differ per handoff.
 */
trait ContextualId
{
    /**
     * @param  string  $key  the query parameter, e.g. `inquiry`
     */
    protected function contextualId(Request $request, string $key): ?int
    {
        $raw = $request->query($key);

        // `query()` hands back an array for `?id[]=1`, which is not an id however it is cast.
        if (! is_string($raw)) {
            return null;
        }

        // `ctype_digit` rejects the empty string, signs, decimals, whitespace and any tail —
        // which is every shape a genuine id is not.
        if ($raw === '' || ! ctype_digit($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }
}
