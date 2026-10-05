import { describe, expect, it } from 'vitest';
import { describeFilters, describeSort } from '../../resources/js/composables/useListFilters.js';

// UX audit M-40: the export dialog printed the query string — "customer: 12", "sort: -total".
describe('describeFilters', () => {
    const chips = [
        { key: 'customer', label: 'Customer', value: 'Nordic Apparel Ltd' },
        { key: 'status', label: 'Status', value: 'Confirmed' },
    ];

    it('says a filter the way the filter bar says it', () => {
        expect(describeFilters([['customer', '12'], ['status', 'confirmed']], chips)).toEqual([
            { key: 'customer', label: 'Customer', value: 'Nordic Apparel Ltd' },
            { key: 'status', label: 'Status', value: 'Confirmed' },
        ]);
    });

    it('says a sort in words', () => {
        expect(describeSort('-total')).toBe('Total, highest or newest first');
        expect(describeSort('due_date')).toBe('Due date, lowest or oldest first');
        expect(describeFilters([['sort', '-total']], [])[0]).toEqual({ key: 'sort', label: 'Sorted by', value: 'Total, highest or newest first' });
    });

    it('still shows a filter the bar does not know, in readable words', () => {
        expect(describeFilters([['customer_id', '12'], ['status', 'pending_approval']], [])).toEqual([
            { key: 'customer_id', label: 'Customer', value: '12' },
            { key: 'status', label: 'Status', value: 'Pending approval' },
        ]);
    });

    it('quotes a search and leaves out paging and blanks', () => {
        expect(describeFilters([['q', 'label'], ['page', '3'], ['per_page', '50'], ['status', '']], [])).toEqual([
            { key: 'q', label: 'Search', value: '“label”' },
        ]);
    });
});
