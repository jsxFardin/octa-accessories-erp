import { describe, expect, it } from 'vitest';
import { addCalendarDays, configureFormatting, date, datetime, isoDate, todayIso } from '../../resources/js/plugins/formatting.js';

describe('calendar dates', () => {
    it('strips a Laravel ISO datetime down to YYYY-MM-DD without a timezone shift', () => {
        expect(isoDate('2026-08-20T00:00:00.000000Z')).toBe('2026-08-20');
        expect(isoDate('2026-08-20')).toBe('2026-08-20');
        expect(isoDate(null)).toBe('');
    });

    it('renders date-only values in the organisation format, not as UTC timestamps', () => {
        configureFormatting({ date_format: 'd M Y', timezone: 'America/New_York', number_locale: 'en-GB' });

        // Midnight UTC is the previous evening in New York; a calendar date must not roll back.
        expect(date('2026-08-20T00:00:00.000000Z')).toBe('20 Aug 2026');
        expect(date('2026-08-20')).toBe('20 Aug 2026');
    });

    it('still converts real timestamps into the factory timezone', () => {
        configureFormatting({ date_format: 'd M Y', timezone: 'Asia/Dhaka', number_locale: 'en-GB', time_format: 'HH:mm' });

        expect(datetime('2026-08-20T18:30:00.000000Z')).toBe('21 Aug 2026 00:30');
    });

    it('adds days on the calendar, never via toISOString', () => {
        expect(addCalendarDays('2026-08-20T00:00:00.000000Z', 10)).toBe('2026-08-30');
    });

    it('todayIso is a calendar day, not a UTC slice', () => {
        expect(todayIso()).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });
});

// UX audit M-51, decision of 5 Oct 2026: lakh grouping with ordinary digits, "BDT" for the taka.
describe('default number format', () => {
    it('groups in lakhs and crores unless the organisation says otherwise', async () => {
        const { configureFormatting, money, number, pcs, pct } = await import('../../resources/js/plugins/formatting.js');

        configureFormatting({ number_locale: 'en-IN', base_currency: 'BDT' });

        expect(pcs(1234567)).toBe('12,34,567');
        expect(money(1234567.5)).toBe('BDT 12,34,567.50');
        expect(number(1234567.25)).toBe('12,34,567.25');
        expect(number(40)).toBe('40');
        expect(number(1.5, 4, 4)).toBe('1.5000');
        expect(pct(12.5, 2)).toBe('12.50%');
        // Ordinary digits, not Bangla ones.
        expect(pcs(1234567)).toMatch(/^[0-9,]+$/);
    });

    it('keeps ordinary digits and English dates when the grouping is Bengali', async () => {
        const { configureFormatting, date, datetime, money, pcs } = await import('../../resources/js/plugins/formatting.js');

        // bn-BD groups the same way as en-IN; it must not also switch the digits to Bengali
        // script or the month names to Bangla — grouping is a number format, not a language.
        configureFormatting({ number_locale: 'bn-BD', base_currency: 'BDT', date_format: 'd M Y', timezone: 'Asia/Dhaka', time_format: 'HH:mm' });

        expect(pcs(1234567)).toBe('12,34,567');
        expect(money(1234567.5)).toBe('BDT 12,34,567.50');
        expect(date('2026-10-06')).toBe('06 Oct 2026');
        expect(datetime('2026-10-06T05:59:00Z')).toBe('06 Oct 2026 11:59');

        configureFormatting({ number_locale: 'en-IN' });
    });

    it('still follows an organisation that chose another grouping', async () => {
        const { configureFormatting, money } = await import('../../resources/js/plugins/formatting.js');

        configureFormatting({ number_locale: 'en-GB' });
        expect(money(1234567.5, 'USD')).toBe('USD 1,234,567.50');
        configureFormatting({ number_locale: 'en-IN' });
    });
});

describe('no figure formats itself', () => {
    it('leaves grouping and decimals to the formatter on every screen', async () => {
        const { readdirSync, readFileSync, statSync } = await import('node:fs');
        const { join } = await import('node:path');
        const walk = (dir) => readdirSync(dir).flatMap((name) => {
            const path = join(dir, name);

            return statSync(path).isDirectory() ? walk(path) : /\.(vue|js)$/.test(path) ? [path] : [];
        });

        // Two list pages work an outstanding amount out to two places for an input's value —
        // arithmetic, not display — and the allocation helper works in cents.
        const allowed = ['plugins/formatting.js', 'plugins/allocation.js', 'Finance/Receipts/Index.vue', 'Finance/Payments/Index.vue'];
        const offenders = [];

        for (const file of walk('resources/js')) {
            if (allowed.some((path) => file.endsWith(path))) continue;

            const source = readFileSync(file, 'utf8');

            if (/\.toLocaleString\(\)|\.toFixed\(/.test(source)) offenders.push(file);
            if (source.includes('৳') && !/\/\/.*৳/.test(source)) offenders.push(`${file} (hard-coded ৳)`);
        }

        expect(offenders).toEqual([]);
    });
});


describe('stored decimals as typed numbers', () => {
    it('turns "12000.000000" into "12000" and leaves ids and text alone', async () => {
        const { typed, typedRecord } = await import('../../resources/js/plugins/formatting.js');

        expect(typed('12000.000000')).toBe('12000');
        expect(typed('520.8333')).toBe('520.8333');
        expect(typed(null)).toBe('');
        expect(typedRecord({ id: 7, qty: '8000.000000', rate_per_m: '12.5000', description: 'Care label 40.5', code: 'PRD-01' }))
            .toEqual({ id: 7, qty: '8000', rate_per_m: '12.5', description: 'Care label 40.5', code: 'PRD-01' });
    });
});

// Planning board: "11,611.25 m" beside a machine's hours is a figure nobody reads to the quarter-metre.
describe('rounded quantities', () => {
    it('drops the decimals a large figure does not need and keeps the ones a small figure does', async () => {
        const { configureFormatting, qtyRound } = await import('../../resources/js/plugins/formatting.js');

        configureFormatting({ number_locale: 'en-GB', decimal_places: 2 });

        expect(qtyRound(11611.25)).toBe('11,611');
        expect(qtyRound('1050000.000000')).toBe('1,050,000');
        expect(qtyRound(100)).toBe('100');
        expect(qtyRound(42.26)).toBe('42.3');
        expect(qtyRound(42)).toBe('42');
        expect(qtyRound(7.255)).toBe('7.26');
        expect(qtyRound(0.5)).toBe('0.5');
        expect(qtyRound(null)).toBe('0');
    });

    it('groups the way the organisation groups', async () => {
        const { configureFormatting, qtyRound } = await import('../../resources/js/plugins/formatting.js');

        configureFormatting({ number_locale: 'en-IN' });

        expect(qtyRound(1050000)).toBe('10,50,000');

        configureFormatting({ number_locale: 'en-GB' });
    });
});
