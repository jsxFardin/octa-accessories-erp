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
