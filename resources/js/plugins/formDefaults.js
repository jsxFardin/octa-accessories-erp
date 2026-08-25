import { isoDate } from '@/plugins/formatting';

/**
 * The starting values for a `ResourceForm`, resolved from the field descriptors and whatever
 * record is being edited.
 *
 * Its own module because it is the part with a rule in it, and a rule that was wrong: a
 * checkbox used to be forced to `false` before its declared `default` was consulted, so every
 * `is_active` field opened unticked on a new record. A customer saved that way is created
 * successfully and then absent from every picker, which reads to the user as a lost save.
 *
 * @param {Array<{fields: Array<{key: string, type?: string, default?: unknown}>}>} sections
 * @param {Record<string, unknown>} initial the record being edited, `{}` when creating
 * @returns {Record<string, unknown>}
 */
export function resolveDefaults(sections, initial = {}) {
    const values = {};

    for (const section of sections ?? []) {
        for (const field of section.fields ?? []) {
            // The declared default wins for every type; a checkbox only falls back to `false`
            // when nothing was declared.
            const fallback = field.default ?? (field.type === 'checkbox' ? false : '');

            // `??`, not `||`: an existing record's `false`, `0` or `''` is its value and must
            // survive editing rather than being replaced by the create-time default.
            const raw = initial?.[field.key] ?? fallback;

            values[field.key] = field.type === 'date' ? isoDate(raw) || raw : raw;
        }
    }

    return values;
}
