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
