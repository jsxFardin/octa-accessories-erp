import { describe, expect, it } from 'vitest';
import {
    dayOf, earliestSlot, groupOpen, implausibleMinutes, readCompact, readFolds, splitAcrossDays, writeCompact, writeFolds,
} from '../../resources/js/planning/board.js';

function memoryStorage(initial = {}) {
    const data = { ...initial };

    return {
        getItem: (key) => (key in data ? data[key] : null),
        setItem: (key, value) => { data[key] = String(value); },
        data,
    };
}

const day = (date, free, holiday = false) => ({ date, free, holiday });

describe('group folding', () => {
    const free = { id: 3, loaded: false, waiting: 0 };
    const busy = { id: 4, loaded: true, waiting: 0 };
    const wanted = { id: 5, loaded: false, waiting: 2 };

    it('folds a group with nothing on it and nothing waiting, and opens the rest', () => {
        const folds = readFolds(memoryStorage());

        expect(groupOpen(free, folds)).toBe(false);
        expect(groupOpen(busy, folds)).toBe(true);
        expect(groupOpen(wanted, folds)).toBe(true);
    });

    it('remembers what the planner folded and opened, across visits', () => {
        const storage = memoryStorage();

        writeFolds({ all: false, groups: { 3: true, 4: false } }, storage);

        const folds = readFolds(storage);

        expect(groupOpen(free, folds)).toBe(true);
        expect(groupOpen(busy, folds)).toBe(false);
        expect(groupOpen(wanted, folds)).toBe(true);
    });

    it('remembers "show all machines", and a group folded by hand after it stays folded', () => {
        const storage = memoryStorage();

        writeFolds({ all: true, groups: { 5: false } }, storage);

        const folds = readFolds(storage);

        expect(groupOpen(free, folds)).toBe(true);
        expect(groupOpen(wanted, folds)).toBe(false);
    });

    it('opens on the defaults when the stored value is unreadable or storage is refused', () => {
        expect(readFolds(memoryStorage({ 'octa.planning.folds': '{not json' }))).toEqual({ all: false, groups: {} });
        expect(readFolds(memoryStorage({ 'octa.planning.folds': '{"all":"yes","groups":{"3":"open"}}' }))).toEqual({ all: false, groups: {} });

        const refusing = { getItem: () => { throw new Error('blocked'); }, setItem: () => { throw new Error('blocked'); } };

        expect(readFolds(refusing)).toEqual({ all: false, groups: {} });
        expect(() => writeFolds({ all: true, groups: {} }, refusing)).not.toThrow();
    });

    it('remembers the compact rows', () => {
        const storage = memoryStorage();

        expect(readCompact(storage)).toBe(false);

        writeCompact(true, storage);

        expect(readCompact(storage)).toBe(true);
    });
});

describe('splitting a step across days', () => {
    it('fills each day in turn and puts what is left on the last', () => {
        // 23.6 h against 7 h days: three full days and 2.6 h on the fourth.
        const { parts, remaining } = splitAcrossDays(1416, [
            day('2026-10-08', 420), day('2026-10-09', 420), day('2026-10-10', 420), day('2026-10-11', 420), day('2026-10-12', 420),
        ]);

        expect(parts).toEqual([
            { date: '2026-10-08', minutes: 420 },
            { date: '2026-10-09', minutes: 420 },
            { date: '2026-10-10', minutes: 420 },
            { date: '2026-10-11', minutes: 156 },
        ]);
        expect(remaining).toBe(0);
        expect(parts.reduce((sum, part) => sum + part.minutes, 0)).toBe(1416);
    });

    it('passes over a holiday and a day that is already full', () => {
        const { parts, remaining } = splitAcrossDays(600, [
            day('2026-10-08', 200), day('2026-10-09', 0, true), day('2026-10-10', 0), day('2026-10-11', 420),
        ]);

        expect(parts).toEqual([
            { date: '2026-10-08', minutes: 200 },
            { date: '2026-10-11', minutes: 400 },
        ]);
        expect(remaining).toBe(0);
    });

    it('takes only what a part-loaded day has left', () => {
        expect(splitAcrossDays(300, [day('2026-10-08', 120), day('2026-10-09', 420)]).parts).toEqual([
            { date: '2026-10-08', minutes: 120 },
            { date: '2026-10-09', minutes: 180 },
        ]);
    });

    it('says what did not fit when the days run out', () => {
        const { parts, remaining } = splitAcrossDays(1000, [day('2026-10-08', 420), day('2026-10-09', 420)]);

        expect(parts).toHaveLength(2);
        expect(remaining).toBe(160);
    });

    it('is one part when a single day holds it, and none when nothing is wanted', () => {
        expect(splitAcrossDays(120, [day('2026-10-08', 420)])).toEqual({ parts: [{ date: '2026-10-08', minutes: 120 }], remaining: 0 });
        expect(splitAcrossDays(0, [day('2026-10-08', 420)])).toEqual({ parts: [], remaining: 0 });
    });
});

describe('earliest slot', () => {
    const machines = [
        { id: 1, days: [day('2026-10-08', 60), day('2026-10-09', 420), day('2026-10-10', 420)] },
        { id: 2, days: [day('2026-10-08', 0, true), day('2026-10-09', 420), day('2026-10-10', 420)] },
        { id: 3, days: [day('2026-10-08', 30), day('2026-10-09', 100), day('2026-10-10', 420)] },
    ];

    it('is the first day with the hours, on the first machine that has them', () => {
        expect(earliestSlot(60, machines)).toEqual({ machineId: 1, parts: [{ date: '2026-10-08', minutes: 60 }] });
        expect(earliestSlot(240, machines)).toEqual({ machineId: 1, parts: [{ date: '2026-10-09', minutes: 240 }] });
    });

    it('prefers an earlier day on a later machine', () => {
        const later = [
            { id: 1, days: [day('2026-10-08', 0), day('2026-10-09', 420)] },
            { id: 2, days: [day('2026-10-08', 420), day('2026-10-09', 420)] },
        ];

        expect(earliestSlot(240, later).machineId).toBe(2);
    });

    it('never offers a day before the step ahead of it finishes', () => {
        expect(earliestSlot(60, machines, { notBefore: '2026-10-10' })).toEqual({ machineId: 1, parts: [{ date: '2026-10-10', minutes: 60 }] });
    });

    it('has nothing to offer a step longer than any day unless it may be split', () => {
        expect(earliestSlot(900, machines)).toBeNull();
    });

    it('splits a long step onto the machine that finishes it soonest', () => {
        // Machine 1: 60 + 420 + 420 = 900 by the 10th. Machine 2 has 840 in the window; machine 3 has 550.
        expect(earliestSlot(900, machines, { allowSplit: true })).toEqual({
            machineId: 1,
            parts: [
                { date: '2026-10-08', minutes: 60 },
                { date: '2026-10-09', minutes: 420 },
                { date: '2026-10-10', minutes: 420 },
            ],
        });

        expect(earliestSlot(480, machines, { allowSplit: true }).parts).toEqual([
            { date: '2026-10-08', minutes: 60 },
            { date: '2026-10-09', minutes: 420 },
        ]);
    });

    it('has nothing to offer when the window cannot hold the step at all', () => {
        expect(earliestSlot(5000, machines, { allowSplit: true })).toBeNull();
        expect(earliestSlot(0, machines)).toBeNull();
    });
});

describe('reading a cell as a day', () => {
    it('gives a holiday and a missing cell no minutes', () => {
        expect(dayOf({ available: 420, load: 120, is_holiday: false }, '2026-10-08')).toEqual({ date: '2026-10-08', free: 300, holiday: false });
        expect(dayOf({ available: 0, load: 0, is_holiday: true }, '2026-10-09')).toEqual({ date: '2026-10-09', free: 0, holiday: true });
        expect(dayOf({ available: 420, load: 500, is_holiday: false }, '2026-10-10').free).toBe(0);
        expect(dayOf(undefined, '2026-10-11')).toEqual({ date: '2026-10-11', free: 0, holiday: false });
    });
});

describe('implausible durations', () => {
    it('flags a step of thousands of hours and leaves a long real one alone', () => {
        expect(implausibleMinutes(3150030)).toBe(true);
        expect(implausibleMinutes(1416)).toBe(false);
        expect(implausibleMinutes(12610)).toBe(false);
        expect(implausibleMinutes(null)).toBe(false);
    });
});
