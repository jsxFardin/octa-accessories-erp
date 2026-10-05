import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * A guard for the copy rules (UX audit M-03, M-04, M-06): no internal terms, no "GRN" or
 * "challan" on its own, and no rule code outside a rule marker, in anything a screen shows.
 *
 * What a screen shows is read out of each page: text between tags, and the attributes and
 * object keys that carry words (title, subtitle, label, hint, description, placeholder, empty,
 * message). Code, comments and `rule="…"` references — which open a tooltip that explains the
 * rule in a sentence — are not checked.
 */
function vueFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        return statSync(path).isDirectory() ? vueFiles(path) : path.endsWith('.vue') ? [path] : [];
    });
}

const WORDS = '(?:title|subtitle|label|hint|description|placeholder|empty|message|confirm-label|add-label|action-label|checkbox-label|crumb|aria-label)';
const VISIBLE = [
    // title="…", :subtitle="`…`"
    new RegExp(`(?<![\\w-]):?${WORDS}="([^"]*)"`, 'g'),
    // label: '…', hint: `…`
    new RegExp(`\\b(?:title|subtitle|label|hint|description|message|placeholder|confirmLabel|cancelLabel|checkboxLabel|empty):\\s*(['\`])((?:(?!\\1).)*)\\1`, 'g'),
    // >text between tags<
    />([^<>]*[A-Za-z]{3}[^<>]*)</g,
];

export function visibleStrings(source) {
    const stripped = source
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '')
        // An interpolation is code: `{{ challan.number }}`, `${grn.id}`.
        .replace(/\{\{[\s\S]*?\}\}/g, '…')
        .replace(/\$\{[^}]*\}/g, '…');

    const template = stripped.slice(stripped.indexOf('<template>'));
    const found = [];

    for (const match of stripped.matchAll(VISIBLE[0])) {
        // A bound attribute (`:title="row.code ?? 'Draft'"`) holds an expression: its words
        // are the string literals inside it, not the variable names around them.
        if (match[0].startsWith(':')) found.push(...[...match[1].matchAll(/(['`])((?:(?!\1).)*)\1/g)].map((literal) => literal[2]));
        else found.push(match[1]);
    }

    for (const match of stripped.matchAll(VISIBLE[1])) found.push(match[2]);

    for (const match of template.matchAll(VISIBLE[2])) found.push(match[1]);

    return found.map((text) => text.trim()).filter((text) => /[A-Za-z]{3}/.test(text));
}

const BANNED = [
    ['internal term', /\b(snapshot\w*|ledger|observer|state machine|immutable|posting path|query scope)\b/i],
    ['GRN used alone', /\bGRNs?\b(?!\))/],
    ['challan used alone', /(?<!\()\bchallans?\b(?!\))/i],
    ['rule code', /\b(BR|QL|P\d)-\d+\b|\b[JIS]\d+:/],
];

describe('banned wording', () => {
    it('recognises what it is looking for', () => {
        const sample = `<script setup>
            // a snapshot is fine in a comment
            const columns = [{ key: 'grn', label: 'GRN' }];
        </script>
        <template>
            <Card title="Lots" rule="BR-39" subtitle="Each line gets a ledger row">
                <p>J3: output exceeds input for {{ challan.number }}</p>
                <p>Delivery note (challan) and goods receipt (GRN) are fine.</p>
            </Card>
        </template>`;

        const hits = visibleStrings(sample).flatMap((text) => BANNED.filter(([, pattern]) => pattern.test(text)).map(([name]) => name));

        expect(hits.sort()).toEqual(['GRN used alone', 'internal term', 'rule code']);
    });

    it('is not on any page', () => {
        const offenders = [];

        for (const file of vueFiles('resources/js/Pages')) {
            for (const text of visibleStrings(readFileSync(file, 'utf8'))) {
                for (const [name, pattern] of BANNED) {
                    const hit = text.match(pattern);

                    if (hit) offenders.push(`${file.replace('resources/js/Pages/', '')}: ${name} “${hit[0]}” in: ${text.slice(0, 90)}`);
                }
            }
        }

        expect(offenders).toEqual([]);
    });
});
