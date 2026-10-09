import { describe, expect, it } from 'vitest';
import { getPath, setPath } from '../../resources/js/plugins/formPath.js';
import { resolveDefaults } from '../../resources/js/plugins/formDefaults.js';

describe('dotted form keys', () => {
    it('reads and writes one level down', () => {
        const form = { attributes: { material: 'Polyester' } };

        expect(getPath(form, 'attributes.material')).toBe('Polyester');

        setPath(form, 'attributes.diameter_mm', 5);

        expect(form.attributes).toEqual({ material: 'Polyester', diameter_mm: 5 });
    });

    it('creates the branch when the record has none', () => {
        const form = {};

        setPath(form, 'attributes.tip_type', 'Plastic');

        expect(form.attributes.tip_type).toBe('Plastic');
    });

    it('resolves a dotted field into a nested default, keeping the record\'s value', () => {
        const sections = [{ title: 'Spec', fields: [
            { key: 'attributes.material', type: 'text' },
            { key: 'attributes.diameter_mm', type: 'number' },
            { key: 'variant_axes', type: 'checkboxes' },
        ] }];

        const values = resolveDefaults(sections, { attributes: { material: 'Cotton' } });

        // The family's fields post nested under `attributes`, which is how the server validates
        // them (`attributes.material`) and how the item stores them.
        expect(values.attributes).toEqual({ material: 'Cotton', diameter_mm: '' });
        expect(values.variant_axes).toEqual([]);
    });
});
