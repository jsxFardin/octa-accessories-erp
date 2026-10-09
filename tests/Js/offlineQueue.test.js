import { describe, expect, it } from 'vitest';
import { describeRejected, rejectedRecords, splitExpired } from '../../resources/js/composables/useOfflineQueue.js';

const HOUR = 60 * 60 * 1000;
const now = Date.parse('2026-10-05T12:00:00.000Z');
const at = (hoursAgo) => new Date(now - hoursAgo * HOUR).toISOString();

describe('splitExpired', () => {
    it('keeps entries inside the four-hour window for posting', () => {
        const queue = [{ key: 'a', occurredAt: at(1) }, { key: 'b', occurredAt: at(3.9) }];

        const { fresh, expired } = splitExpired(queue, now);

        expect(fresh.map((e) => e.key)).toEqual(['a', 'b']);
        expect(expired).toEqual([]);
    });

    // UX audit C-07: these used to be skipped and then overwritten out of the queue, so a long
    // outage lost output with no trace. They must come back for the caller to file as rejected.
    it('hands back expired entries instead of losing them', () => {
        const queue = [{ key: 'old', occurredAt: at(9) }, { key: 'new', occurredAt: at(0.5) }];

        const { fresh, expired } = splitExpired(queue, now);

        expect(fresh.map((e) => e.key)).toEqual(['new']);
        expect(expired.map((e) => e.key)).toEqual(['old']);
        expect(fresh.length + expired.length).toBe(queue.length);
    });

    it('treats exactly four hours as still inside the window', () => {
        const { fresh, expired } = splitExpired([{ key: 'edge', occurredAt: at(4) }], now);

        expect(fresh).toHaveLength(1);
        expect(expired).toHaveLength(0);
    });
});

describe('queuedOutputFor', () => {
    // UX audit H-32: a booking that was only queued did not show in the tiles.
    it('adds up what is waiting on this device for one operation', async () => {
        const { queuedOutputFor } = await import('../../resources/js/composables/useOfflineQueue.js');

        const queue = [
            { url: '/api/v1/operations/7/log', payload: { good_qty: 500, waste_qty: 12, input_qty: 520 } },
            { url: '/api/v1/operations/7/log', payload: { good_qty: 250, waste_qty: 0 } },
            { url: '/api/v1/operations/7/downtime', payload: { minutes: 15 } },
            { url: '/api/v1/operations/8/log', payload: { good_qty: 999 } },
        ];

        expect(queuedOutputFor(7, queue)).toEqual({ good: 750, waste: 12, input: 520, count: 2 });
        expect(queuedOutputFor(9, queue)).toEqual({ good: 0, waste: 0, input: 0, count: 0 });
    });
});

// UX audit H-33: the rejected list was a count. These are what the "Not sent" screen reads.
describe('rejected records', () => {
    const refused = {
        key: 'k1',
        url: '/api/v1/operations/42/log',
        payload: { good_qty: 500, waste_qty: 10, input_qty: 520 },
        occurredAt: at(2),
        rejectedAt: at(1),
        status: 422,
        reason: 'refused',
        body: JSON.stringify({ message: 'J3: …', code: 'output_exceeds_input', params: { output: 510, input: 400 } }),
        meta: { job: 'JC-26-00007', step: 'Weaving', unit: 'm', operator: 'Rahim' },
    };

    it('names the job, step, unit, operator, figures and the coded reason', () => {
        expect(describeRejected(refused)).toMatchObject({
            key: 'k1',
            operationId: 42,
            action: 'log',
            expired: false,
            code: 'output_exceeds_input',
            params: { output: 510, input: 400 },
            job: 'JC-26-00007',
            step: 'Weaving',
            unit: 'm',
            operator: 'Rahim',
            payload: { good_qty: 500 },
        });
    });

    it('marks a record that outlived the window as expired', () => {
        const record = describeRejected({ ...refused, status: 0, body: '', reason: 'expired' });

        expect(record.expired).toBe(true);
        expect(record.code).toBeNull();
    });

    it('still describes a record filed before the terminal kept the job with it', () => {
        const record = describeRejected({ key: 'old', url: '/api/v1/operations/9/finish', payload: {}, occurredAt: at(5), status: 500, body: '<html>Server Error</html>' });

        expect(record).toMatchObject({ operationId: 9, action: 'finish', job: null, code: null, status: 500 });
    });

    it('lists them oldest first', () => {
        const list = [{ ...refused, key: 'late', occurredAt: at(1) }, { ...refused, key: 'early', occurredAt: at(3) }];

        expect(rejectedRecords(list).map((record) => record.key)).toEqual(['early', 'late']);
    });
});
