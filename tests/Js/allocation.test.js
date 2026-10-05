import { describe, expect, it } from 'vitest';
import { allocatedTotal, allocationProblem, spreadOldestFirst } from '../../resources/js/plugins/allocation.js';

const invoices = [
    { id: 1, outstanding: 1000, label: 'INV-1' },
    { id: 2, outstanding: 600, label: 'INV-2' },
    { id: 3, outstanding: 250.5, label: 'INV-3' },
];

describe('spreadOldestFirst', () => {
    // UX audit H-27: one cheque for several invoices used to be several entries.
    it('settles the oldest in full and carries the rest forward', () => {
        expect(spreadOldestFirst(1400, invoices)).toEqual({ 1: '1000.00', 2: '400.00' });
    });

    it('stops when everything offered is settled, leaving the surplus unallocated', () => {
        expect(spreadOldestFirst(5000, invoices)).toEqual({ 1: '1000.00', 2: '600.00', 3: '250.50' });
    });

    it('reaches only the first document when the money is short', () => {
        expect(spreadOldestFirst(300, invoices)).toEqual({ 1: '300.00' });
    });

    it('allocates nothing from nothing', () => {
        expect(spreadOldestFirst(0, invoices)).toEqual({});
        expect(spreadOldestFirst('', invoices)).toEqual({});
        expect(spreadOldestFirst(-50, invoices)).toEqual({});
    });

    it('works in cents, so the shares add up to exactly what was given', () => {
        const awkward = [{ id: 1, outstanding: 0.1 }, { id: 2, outstanding: 0.2 }, { id: 3, outstanding: 10 }];
        const result = spreadOldestFirst(0.3, awkward);

        expect(result).toEqual({ 1: '0.10', 2: '0.20' });
        expect(allocatedTotal(result)).toBe(0.3);
    });

    it('skips a document with nothing outstanding', () => {
        expect(spreadOldestFirst(100, [{ id: 1, outstanding: 0 }, { id: 2, outstanding: 80 }])).toEqual({ 2: '80.00' });
    });
});

describe('allocationProblem', () => {
    it('accepts a full and a partial allocation', () => {
        expect(allocationProblem(1400, { 1: '1000.00', 2: '400.00' }, invoices)).toBeNull();
        expect(allocationProblem(2000, { 1: '1000.00' }, invoices)).toBeNull();
    });

    it('says which document is over its balance', () => {
        expect(allocationProblem(5000, { 2: '600.01' }, invoices)).toBe('INV-2: more than its outstanding balance.');
    });

    it('says by how much the allocations exceed the money', () => {
        expect(allocationProblem(1000, { 1: '1000.00', 2: '0.50' }, invoices)).toBe('0.50 more is set against documents than was received.');
    });

    it('requires at least one allocation', () => {
        expect(allocationProblem(1000, {}, invoices)).toBe('Set the money against at least one document.');
    });
});
