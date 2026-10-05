<?php

declare(strict_types=1);

namespace App\Support\Text;

use Illuminate\Support\Facades\DB;

/**
 * Turning the system's own identifiers into words a person reads.
 *
 * Messages are written by the code that enforces a rule, and that code knows the rule by its
 * number, the status by its key and the permission by its name: "J3: output exceeds input",
 * "moved to pending_approval", "You do not have the [job_card.close] permission". Those are the
 * right names in a log and the wrong ones on a screen (UX audit M-04, M-05).
 */
final class Plain
{
    /** Words that stay upper-case when a key is spelled out. */
    private const ACRONYMS = ['qc', 'po', 'lc', 'grn', 'ncr', 'rfq', 'bom', 'fg', 'capa', 'mrp', 'coc', 'pod', 'tt', 'da', 'dp', 'aql'];

    /**
     * A rule reference: BR-29, J3, QC1, P0-3, P1-2, QL-5, DF-4, PD-3, Gate 1, 06-rbac §5 —
     * alone or several joined with "·".
     */
    private const RULE = '(?:BR-\d+|P\d-\d+(?:\.\d+)?|QL-\d+|DF-\d+|PD-\d+|IN-\d+|Gate \d|\d{2}-[a-z]+ §\d+|[A-Z]{1,2}\d{1,2})';

    /** "pending_approval" → "pending approval"; "qc_pending" → "QC pending". For the middle of a sentence. */
    public static function status(?string $status): string
    {
        $words = array_map(
            fn (string $word): string => in_array($word, self::ACRONYMS, true) ? strtoupper($word) : $word,
            explode(' ', str_replace(['_', '-'], ' ', strtolower(trim((string) $status)))),
        );

        return implode(' ', $words);
    }

    /** The same, to start a sentence or stand as a label: "Pending approval", "QC pending". */
    public static function statusLabel(?string $status): string
    {
        return ucfirst(self::status($status));
    }

    /**
     * A message with its rule references taken off: "J3: output exceeds input" → "Output exceeds
     * input", "Final inspection is still pending. (P1-1 · QC1)" → "Final inspection is still pending."
     *
     * The reference is how support finds the rule; it stays in the logs, the API and the rule
     * tooltips. On a flash or under a field it reads as an error code.
     */
    public static function message(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $rules = self::RULE.'(?:\s*·\s*'.self::RULE.')*';

        // "J3: …", "BR-46 — …", "P1-2 · BR-24: …"
        $text = preg_replace('/^\s*'.$rules.'\s*(?::|—|–|-)\s+/u', '', $message) ?? $message;
        // "… (J1)", "… (P1-1 · QC1)." — a reference in brackets, anywhere.
        $text = preg_replace('/\s*\(\s*'.$rules.'\s*\)/u', '', $text) ?? $text;
        // "… [draft] to [in_transit] …" — a bracketed key, spelled out.
        $text = preg_replace_callback('/\[([a-z]+(?:_[a-z]+)*)\]/', fn (array $m): string => self::status($m[1]), $text) ?? $text;

        return self::upperFirst(trim($text));
    }

    /**
     * What a permission lets someone do, in words: "job_card.close" → "close a job card".
     *
     * Read from the permission's own label where there is one, so the wording matches the
     * roles screen an administrator would go to next.
     */
    public static function permission(string $permission): string
    {
        static $labels = [];

        $labels[$permission] ??= (string) (DB::table('permissions')->where('name', $permission)->value('label') ?? '');

        if ($labels[$permission] !== '') {
            return lcfirst(self::status(str_replace(' ', '_', $labels[$permission])));
        }

        [$subject, $action] = array_pad(explode('.', $permission, 2), 2, 'use');

        return self::status($action).' '.self::status($subject);
    }

    private static function upperFirst(string $text): string
    {
        return $text === '' ? $text : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
