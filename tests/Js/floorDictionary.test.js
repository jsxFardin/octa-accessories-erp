import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { bangla, english, guide, has, keys, label, refusal, unitLabel, WASTE_TYPES } from '../../resources/js/floor/dictionary.js';

describe('floor dictionary', () => {
    it('has a Bangla and an English line for every key', () => {
        for (const key of keys()) {
            expect(bangla(key).trim(), `${key} (bn)`).not.toBe('');
            expect(english(key).trim(), `${key} (en)`).not.toBe('');
            // The Bangla half is actually Bangla, not a copy of the English.
            expect(/[ঀ-৿]/.test(bangla(key)), `${key} has Bangla script`).toBe(true);
        }
    });

    it('never shows a rule code to an operator', () => {
        for (const key of keys()) {
            expect(`${bangla(key)} ${english(key)}`, key).not.toMatch(/\b(J[0-9]|BR-[0-9]|QC[0-9]|I[0-9]):?/);
        }
    });

    it('puts both languages on a label', () => {
        expect(label('good')).toBe('ভালো · Good');
        expect(label('waiting_count', { n: '3' })).toBe('3টি অপেক্ষায় · 3 WAITING');
    });

    it('gives guidance in Bangla by default', () => {
        expect(guide('not_sent_empty')).toBe('সব রেকর্ড পাঠানো হয়েছে।');
    });

    // UX audit H-34: the server now names its refusals; the terminal says them in both languages.
    it('turns a coded refusal into a sentence with its figures', () => {
        const text = refusal({ code: 'output_exceeds_input', params: { output: 5200, input: 5000 } });

        expect(text).toContain((5200).toLocaleString());
        expect(text).toContain((5000).toLocaleString());
        expect(text).toContain('Enter the input first.');
        expect(text).toMatch(/[ঀ-৿]/);
    });

    it('covers every code the server can send', () => {
        const codes = ['output_exceeds_input', 'input_exceeds_plan', 'output_exceeds_ceiling', 'job_card_not_released',
            'step_not_open', 'earlier_step_unfinished', 'inspection_needed', 'input_exceeds_previous_output',
            'waste_reason_needed', 'nothing_booked', 'no_machine'];

        for (const code of codes) {
            expect(refusal({ code, params: {} }), code).not.toBe(label('error.unknown'));
        }
    });

    it('falls back to a plain instruction for a refusal it does not know', () => {
        expect(refusal({ code: 'something_new' })).toBe(label('error.unknown'));
        expect(refusal({ message: 'HTTP 500' })).toBe(label('error.unknown'));
    });

    it('shows a missing key as itself rather than as nothing', () => {
        expect(label('no_such_key')).toBe('[no_such_key] · [no_such_key]');
    });

    it('says the unit in both languages, and passes an unknown one through', () => {
        expect(unitLabel('m')).toBe('মিটার · m');
        expect(unitLabel('pcs')).toBe('পিস · pcs');
        expect(unitLabel('kg')).toBe('kg');
    });

    it('has a word for every kind of waste', () => {
        for (const type of WASTE_TYPES) expect(has(`waste_${type}`), type).toBe(true);
    });

    // The point of one dictionary is that a screen cannot say something that is not in it.
    it('has every line the floor screens ask for, and no Bangla typed into a screen', () => {
        const screens = [
            'resources/js/Pages/Floor/Login.vue',
            'resources/js/Pages/Floor/Queue.vue',
            'resources/js/Pages/Floor/Operation.vue',
            'resources/js/Components/Floor/NotSent.vue',
            'resources/js/Layouts/FloorLayout.vue',
        ];

        for (const file of screens) {
            const source = readFileSync(file, 'utf8');

            for (const [, key] of source.matchAll(/\b(?:label|guide)\('([a-z_.]+)'/g)) {
                expect(has(key), `${file} asks for "${key}"`).toBe(true);
            }

            const template = source.slice(source.indexOf('<template>'))
                .replace(/<!--[\s\S]*?-->/g, '')
                // The language switch names Bangla in Bangla; that one word is not a sentence.
                .replace("'বাংলা'", '');

            expect(/[\u0980-\u09FF]/.test(template), `${file} has Bangla outside the dictionary`).toBe(false);
        }
    });
});
