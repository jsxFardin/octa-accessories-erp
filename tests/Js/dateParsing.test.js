import { describe, expect, it } from 'vitest';
import { parseTypedDate, typedDate } from '../../resources/js/plugins/formatting.js';

describe('parseTypedDate', () => {
    // UX audit H-02: typing 20/10/2026 used to empty the field without a word.
    it.each([
        ['20/10/2026', '2026-10-20'],
        ['20-10-2026', '2026-10-20'],
        ['20.10.2026', '2026-10-20'],
        ['20 10 2026', '2026-10-20'],
        ['5/3/2026', '2026-03-05'],
        ['05/03/26', '2026-03-05'],
        ['5.3.26', '2026-03-05'],
        [' 20/10/2026 ', '2026-10-20'],
    ])('reads %s as day first', (typed, expected) => {
        expect(parseTypedDate(typed)).toBe(expected);
    });

    it('never reads the American order', () => {
        // The fifth of October, not the tenth of May.
        expect(parseTypedDate('05/10/2026')).toBe('2026-10-05');
        // There is no month 13, and it is not quietly swapped.
        expect(parseTypedDate('10/13/2026')).toBe('');
    });

    it.each([
        ['20 Oct 2026', '2026-10-20'],
        ['20 October 2026', '2026-10-20'],
        ['20-Oct-26', '2026-10-20'],
        ['20Oct2026', '2026-10-20'],
        ['05 Sept 2026', '2026-09-05'],
        ['5 sep 26', '2026-09-05'],
        ['1 JAN 2027', '2027-01-01'],
    ])('reads the month name in %s', (typed, expected) => {
        expect(parseTypedDate(typed)).toBe(expected);
    });

    it.each([
        ['2026-10-20', '2026-10-20'],
        ['2026-10-20T00:00:00.000000Z', '2026-10-20'],
        ['2026-1-5', '2026-01-05'],
    ])('still reads ISO %s', (typed, expected) => {
        expect(parseTypedDate(typed)).toBe(expected);
    });

    it('puts two-digit years in the right century', () => {
        expect(parseTypedDate('01/01/69')).toBe('2069-01-01');
        expect(parseTypedDate('01/01/70')).toBe('1970-01-01');
    });

    it.each(['31/02/2026', '00/10/2026', '32/01/2026', '29/02/2027', 'tomorrow', '20/10', '2026', '20 Foo 2026', '20/10/202', ''])(
        'refuses %s',
        (typed) => {
            expect(parseTypedDate(typed)).toBe('');
        },
    );

    it('accepts a real leap day', () => {
        expect(parseTypedDate('29/02/2028')).toBe('2028-02-29');
    });

    it('handles null and undefined', () => {
        expect(parseTypedDate(null)).toBe('');
        expect(parseTypedDate(undefined)).toBe('');
    });
});

describe('typedDate', () => {
    it('writes an ISO date the way it is typed', () => {
        expect(typedDate('2026-10-05')).toBe('05/10/2026');
        expect(typedDate('')).toBe('');
    });

    it('round-trips through the parser', () => {
        expect(parseTypedDate(typedDate('2026-02-28'))).toBe('2026-02-28');
    });
});
