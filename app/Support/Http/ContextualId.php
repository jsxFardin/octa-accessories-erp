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
 * Whether the viewer may **read** the record named is asked here too, by
 * `contextualId(..., permission: ...)` — BR-54's second question. It was documented as applying
 * to every handoff and implemented on two of them, so `/grns/create?po=1` handed a QC inspector
 * who is refused `/purchase-orders/1` outright that order's supplier, currency, rates and every
 * line on it. A parameter must not reach around a permission the same user is refused at the
 * front door.
 *
 * Whether the record is in a *state* the handoff allows stays with the caller: that is a domain
 * question and it differs per handoff.
 */
trait ContextualId
{
    /**
     * @param  string  $key  the query parameter, e.g. `inquiry`
     * @param  list<string>|string|null  $permission  the source document's read permission — any
     *                                                one of them is enough. Omitted only where
     *                                                the handoff names no separate source record.
     */
    protected function contextualId(Request $request, string $key, array|string|null $permission = null): ?int
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

        if ($id <= 0) {
            return null;
        }

        // BR-54 — may this viewer read the record the parameter names? A user refused the source
        // document itself must not receive it through a query parameter on another screen. The
        // handoff resolves to nothing, exactly as a malformed id does, and the form still works
        // without a source behind it.
        if ($permission !== null) {
            $user = $request->user();
            $permitted = false;

            foreach ((array) $permission as $name) {
                if ($user?->hasPermission($name)) {
                    $permitted = true;
                    break;
                }
            }

            if (! $permitted) {
                return null;
            }
        }

        return $id;
    }
}
