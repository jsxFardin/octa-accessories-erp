<?php

declare(strict_types=1);

namespace App\Support\Text;

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

    /** What a record is called in a sentence, where the key alone would not say it. */
    private const SUBJECTS = [
        'grn' => 'goods receipt', 'delivery_challan' => 'delivery note', 'bom' => 'bill of materials',
        'coc' => 'chain-of-custody record', 'mrp' => 'material plan', 'pod' => 'proof of delivery',
        'stock_lot' => 'lot', 'stock_issue' => 'material issue', 'item' => 'material', 'uom' => 'unit',
        'fg_receipt' => 'finished goods receipt', 'sales_invoice' => 'invoice', 'product_spec' => 'product specification',
        'reference_data' => 'list', 'waste' => 'waste record', 'downtime' => 'downtime record',
        'tax' => 'tax rate', 'ncr' => 'NCR', 'rfq' => 'RFQ', 'qc_inspection' => 'QC inspection',
    ];

    /** Plurals that are not the singular plus "s". */
    private const PLURALS = [
        'bill of materials' => 'bills of materials', 'proof of delivery' => 'proofs of delivery',
        'letter of credit' => 'letters of credit', 'physical count' => 'physical counts',
    ];

    /**
     * How each kind of permission reads after "permission to …". `{a}` is the record with its
     * article ("a job card", "an invoice"), `{s}` its plural, `{the}` the bare name.
     */
    private const ACTIONS = [
        'view_any' => 'see the list of {s}', 'view' => 'open {a}', 'view_own' => 'see your own {s}',
        'create' => 'create {a}', 'update' => 'edit {a}', 'delete' => 'delete {a}',
        'export' => 'export {s}', 'import' => 'import {s}',
        'waive_material' => 'release {a} without all its material', 'make_current' => 'make {a} the current one',
        'lock_period' => 'close a chain-of-custody period', 'override_margin' => 'approve {a} below the minimum margin',
        'override_tolerance' => 'approve {a} outside its tolerance', 'release_credit_hold' => 'release {a} from credit hold',
        'short_close' => 'close {a} short', 'print_barcode' => 'print labels for {a}', 'approve_variance' => 'approve a variance on {a}',
        'assign_role' => 'give {a} a role', 'dashboard' => 'see the dashboard', 'concession' => 'accept {a} on concession',
        'log' => 'book output on {a}', 'run' => 'run the {the}', 'cost' => 'see the cost of {a}', 'progress' => 'move {a} forward',
    ];

    /** What a kind of record is called: "delivery_challan" → "delivery note", "job_card" → "job card". */
    public static function record(string $key): string
    {
        return self::SUBJECTS[$key] ?? self::status($key);
    }

    /**
     * What a permission lets someone do, in words: "job_card.close" → "close a job card",
     * "sales_invoice.view_any" → "see the list of invoices".
     *
     * Built from the key, not from the stored label: the labels read "Close Job Card", which
     * lower-cased into "close job card" — no article, and "list job card" for a list.
     */
    public static function permission(string $permission): string
    {
        [$subject, $action] = array_pad(explode('.', $permission, 2), 2, 'use');

        $name = self::record($subject);
        $plural = self::PLURALS[$name] ?? (preg_match('/(s|x|ch|sh)$/', $name) ? $name.'es' : (preg_match('/[^aeiou]y$/', $name) ? substr($name, 0, -1).'ies' : $name.'s'));
        // "an invoice", "an NCR", "an RFQ" — by sound, so a spelled-out letter counts.
        $article = preg_match('/^(?!us|uni)([aeiou]|(?:[FHLMNRSX])[A-Z]*\b)/', $name) ? 'an' : 'a';

        $template = self::ACTIONS[$action] ?? self::status($action).' {a}';

        return strtr($template, ['{a}' => "{$article} {$name}", '{s}' => $plural, '{the}' => $name]);
    }

    /** What happens to a record when it reaches a status, as it reads after "cannot be …". */
    private const VERBS = [
        'draft' => 'returned to draft', 'submitted' => 'submitted', 'pending_approval' => 'submitted for approval',
        'approved' => 'approved', 'rejected' => 'rejected', 'cancelled' => 'cancelled', 'closed' => 'closed',
        'issued' => 'issued', 'posted' => 'posted', 'sent' => 'marked as sent', 'accepted' => 'accepted',
        'revised' => 'revised', 'confirmed' => 'confirmed', 'planned' => 'planned', 'released' => 'released',
        'in_production' => 'put into production', 'on_hold' => 'put on hold', 'completed' => 'completed',
        'qc_pending' => 'sent to QC', 'in_transit' => 'marked as in transit', 'delivered' => 'marked as delivered',
        'returned' => 'returned', 'received' => 'received', 'paid' => 'marked as paid', 'partially_paid' => 'marked as partly paid',
        'refunded' => 'refunded', 'applied' => 'applied', 'reconciled' => 'reconciled', 'counting' => 'put back to counting',
        'packed' => 'confirmed as packed', 'dispatched' => 'dispatched', 'verified' => 'verified', 'superseded' => 'superseded',
        'active' => 'activated', 'current' => 'made current', 'investigating' => 'put under investigation',
        'won' => 'marked as won', 'lost' => 'marked as lost', 'quoted' => 'marked as quoted', 'skipped' => 'skipped',
        'material_pending' => 'held for material', 'credit_hold' => 'put on credit hold', 'open' => 'reopened',
    ];

    /**
     * Why a status change is refused, as a sentence: "Sales return is posted, so it cannot be
     * cancelled." A status with no verb of its own falls back to "cannot be moved to Verified".
     */
    public static function refusedChange(string $record, string $from, string $to): string
    {
        $tail = isset(self::VERBS[$to])
            ? 'cannot be '.self::VERBS[$to]
            : 'cannot be moved to '.self::statusLabel($to);

        return self::upperFirst($record).' is '.self::status($from).", so it {$tail}.";
    }

    private static function upperFirst(string $text): string
    {
        return $text === '' ? $text : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
