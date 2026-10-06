import { describe, expect, it } from 'vitest';
import { minutes } from '@/plugins/formatting';

describe('minutes', () => {
    it('keeps short spans in minutes and says hours from an hour up', () => {
        expect(minutes(41)).toBe('41 min');
        expect(minutes(40.87)).toBe('41 min');
        expect(minutes(161.2)).toBe('2.7 h');
        expect(minutes(1786.69)).toBe('29.8 h');
    });

    it('drops the decimal once the figure is large, and groups thousands', () => {
        expect(minutes(12610)).toBe('210 h');
        expect(minutes(3150030)).toBe('52,501 h');
    });

    it('survives nothing', () => {
        expect(minutes(null)).toBe('0 min');
        expect(minutes('x')).toBe('0 min');
    });
});
