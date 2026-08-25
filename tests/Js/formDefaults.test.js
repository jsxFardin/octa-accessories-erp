import { describe, expect, it } from 'vitest';
import { resolveDefaults } from '../../resources/js/plugins/formDefaults.js';

const sections = (...fields) => [{ title: 'Section', fields }];

describe('resource form defaults', () => {
    it('honours a checkbox default when creating', () => {
        // The regression this exists for: `is_active` is declared `default: true` on customers,
        // items, machines, suppliers and products, and every one of them opened unticked. A
        // customer saved that way is created successfully and then absent from every picker.
        const values = resolveDefaults(
            sections({ key: 'is_active', type: 'checkbox', default: true }),
            {},
        );

        expect(values.is_active).toBe(true);
    });

    it('leaves a checkbox with no declared default unticked', () => {
        const values = resolveDefaults(
            sections({ key: 'is_approved', type: 'checkbox' }),
            {},
        );

        expect(values.is_approved).toBe(false);
    });

    it('keeps an existing record\'s false rather than replacing it with the default', () => {
        // Editing an archived customer must not silently reactivate it on save.
        const values = resolveDefaults(
            sections({ key: 'is_active', type: 'checkbox', default: true }),
            { is_active: false },
        );

        expect(values.is_active).toBe(false);
    });

    it('keeps a stored zero and a stored empty string', () => {
        const values = resolveDefaults(
            sections(
                { key: 'credit_limit', type: 'number', default: 0 },
                { key: 'notes', default: 'boilerplate' },
            ),
            { credit_limit: 0, notes: '' },
        );

        expect(values.credit_limit).toBe(0);
        expect(values.notes).toBe('');
    });

    it('falls back to a declared default for a plain field, and to empty without one', () => {
        const values = resolveDefaults(
            sections(
                { key: 'kind', type: 'select', default: 'manufacturer' },
                { key: 'phone' },
            ),
            {},
        );

        expect(values.kind).toBe('manufacturer');
        expect(values.phone).toBe('');
    });

    it('normalises a date to the ISO day the input expects', () => {
        const values = resolveDefaults(
            sections({ key: 'opened_on', type: 'date' }),
            { opened_on: '2026-08-25T00:00:00.000000Z' },
        );

        expect(values.opened_on).toBe('2026-08-25');
    });
});
