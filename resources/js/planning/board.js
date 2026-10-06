/*
 * The planning board's arithmetic, kept out of the page so it can be tested without one.
 *
 * Everything here is in minutes, as the server sends them. A "day" is what a machine has on a
 * date: `{ date, free, holiday }`, in date order.
 */

const FOLD_KEY = 'octa.planning.folds';
const COMPACT_KEY = 'octa.planning.compact';

/** A step this long is a routing error, not a plan: 500 hours is three months of single shifts. */
const IMPLAUSIBLE_MINUTES = 500 * 60;

const EPSILON = 0.0001;

function storageOrNull(storage) {
    if (storage) return storage;

    return typeof localStorage === 'undefined' ? null : localStorage;
}

/**
 * Which groups the planner opened or folded by hand, and whether they asked for all of them.
 *
 * `groups` holds only the groups that were touched; an untouched group follows the default.
 *
 * @returns {{ all: boolean, groups: Record<string, boolean> }}
 */
export function readFolds(storage = null) {
    const empty = { all: false, groups: {} };

    try {
        const stored = JSON.parse(storageOrNull(storage)?.getItem(FOLD_KEY) ?? 'null');

        if (!stored || typeof stored !== 'object') return empty;

        const groups = {};

        for (const [id, open] of Object.entries(stored.groups ?? {})) {
            if (typeof open === 'boolean') groups[id] = open;
        }

        return { all: stored.all === true, groups };
    } catch {
        // Unreadable or blocked storage is the same as none: the board opens on its defaults.
        return empty;
    }
}

export function writeFolds(folds, storage = null) {
    try {
        storageOrNull(storage)?.setItem(FOLD_KEY, JSON.stringify({ all: folds.all === true, groups: folds.groups ?? {} }));
    } catch {
        // Private windows refuse the write; the fold still holds for this visit.
    }
}

/**
 * Whether a group's machines are on show. The planner's own choice wins; otherwise a group is
 * open when it has work on it or waiting for it, and folded when it is simply free.
 */
export function groupOpen(group, folds) {
    const chosen = folds?.groups?.[group.id];

    if (typeof chosen === 'boolean') return chosen;

    return Boolean(folds?.all) || Boolean(group.loaded) || group.waiting > 0;
}

export function readCompact(storage = null) {
    try {
        return storageOrNull(storage)?.getItem(COMPACT_KEY) === '1';
    } catch {
        return false;
    }
}

export function writeCompact(compact, storage = null) {
    try {
        storageOrNull(storage)?.setItem(COMPACT_KEY, compact ? '1' : '0');
    } catch {
        // As above.
    }
}

export function implausibleMinutes(value) {
    return Number(value) > IMPLAUSIBLE_MINUTES;
}

/** What a board cell has left, as a day. A missing cell is a day with nothing to give. */
export function dayOf(cell, date) {
    if (!cell) return { date, free: 0, holiday: false };

    return {
        date,
        free: cell.is_holiday ? 0 : Math.max(0, Number(cell.available) - Number(cell.load)),
        holiday: Boolean(cell.is_holiday),
    };
}

/**
 * Spread a step forward from the first day given, a day's free minutes at a time.
 *
 * A holiday or a day already full is passed over, not planned into: the step waits there and
 * carries on the next day the machine has time. `remaining` is what the days given could not
 * hold — more than zero means the window ran out before the step did.
 *
 * @returns {{ parts: Array<{ date: string, minutes: number }>, remaining: number }}
 */
export function splitAcrossDays(wanted, days) {
    const parts = [];
    let remaining = Math.max(0, Number(wanted) || 0);

    for (const day of days) {
        if (remaining <= EPSILON) break;
        if (day.holiday || day.free <= EPSILON) continue;

        const taken = Math.min(day.free, remaining);

        parts.push({ date: day.date, minutes: taken });
        remaining -= taken;
    }

    return { parts, remaining: remaining <= EPSILON ? 0 : remaining };
}

/**
 * The first place a step can go.
 *
 * One day that holds the whole step is preferred: the earliest such day, and on that day the
 * first machine in board order. Only when no single day can hold it — and `allowSplit` says a
 * step may run over several — is it spread, and then the run that *finishes* soonest wins.
 *
 * `notBefore` is the day the step ahead of it finishes; nothing earlier is offered.
 *
 * @param {number} wanted
 * @param {Array<{ id: number, days: Array<{ date: string, free: number, holiday: boolean }> }>} machines
 * @returns {{ machineId: number, parts: Array<{ date: string, minutes: number }> } | null}
 */
export function earliestSlot(wanted, machines, { notBefore = null, allowSplit = false } = {}) {
    const minutes = Number(wanted) || 0;

    if (minutes <= 0) return null;

    const open = machines.map((machine) => ({
        id: machine.id,
        days: machine.days.filter((day) => !notBefore || day.date >= notBefore),
    }));

    let best = null;

    for (const machine of open) {
        const day = machine.days.find((d) => !d.holiday && d.free + EPSILON >= minutes);

        if (day && (!best || day.date < best.parts[0].date)) {
            best = { machineId: machine.id, parts: [{ date: day.date, minutes }] };
        }
    }

    if (best || !allowSplit) return best;

    for (const machine of open) {
        // From the machine's first open day: starting later only finishes later, or not at all.
        const { parts, remaining } = splitAcrossDays(minutes, machine.days);

        if (remaining > 0) continue;

        const finish = parts[parts.length - 1].date;
        const bestFinish = best?.parts[best.parts.length - 1].date;

        if (!best || finish < bestFinish) best = { machineId: machine.id, parts };
    }

    return best;
}
