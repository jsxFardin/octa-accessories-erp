import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { ruleSentences } from '../../resources/js/plugins/rules.js';
import { titleCase } from '../../resources/js/plugins/formatting.js';

function vueFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        return statSync(path).isDirectory() ? vueFiles(path) : path.endsWith('.vue') ? [path] : [];
    });
}

// UX audit M-04: a rule marker whose code is not in the dictionary opens on "Enforced by an
// internal rule." — a tooltip that explains nothing.
describe('rule markers', () => {
    it('every rule a screen refers to has a sentence', () => {
        const missing = [];

        for (const file of vueFiles('resources/js')) {
            const source = readFileSync(file, 'utf8');
            // Static references only: rule="…" on a component, or rule: '…' in a field list.
            const references = [...source.matchAll(/(?<![:\w-])rule="([^"]+)"/g), ...source.matchAll(/\brule: '([^']+)'/g)].map((match) => match[1]);

            for (const reference of references) {
                for (const code of reference.split('·').map((part) => part.trim()).filter(Boolean)) {
                    if (ruleSentences(code).length === 0) missing.push(`${file}: ${code}`);
                }
            }
        }

        expect(missing).toEqual([]);
    });

    it('explains a rule without internal words', () => {
        for (const code of ['I1', 'I5', '06-rbac §4', '06-rbac §5', 'PD-3', 'J6']) {
            expect(ruleSentences(code).join(' '), code).not.toMatch(/query scope|append-only|deploy|ledger row|snapshot|trigger|observer/i);
        }
    });
});

// UX audit L-07, M-07: "Qc Pending", "Po", "Tt".
describe('titleCase', () => {
    it('keeps acronyms as acronyms', () => {
        expect(titleCase('qc_pending')).toBe('QC Pending');
        expect(titleCase('po')).toBe('PO');
        expect(titleCase('tt')).toBe('TT');
        expect(titleCase('ncr_raised')).toBe('NCR Raised');
    });

    it('still title-cases ordinary keys', () => {
        expect(titleCase('pending_approval')).toBe('Pending Approval');
        expect(titleCase('in_transit')).toBe('In Transit');
        expect(titleCase('')).toBe('');
        expect(titleCase(null)).toBe('');
    });
});
