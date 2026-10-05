import { ref } from 'vue';

/**
 * What the list on screen is narrowed by, in the words the filter bar uses.
 *
 * The filter bar and the export dialog sit in different slots of a page and cannot hand each
 * other props, but the dialog has to say what it is about to export — and it used to say it in
 * raw query keys: "customer: 12", "sort: -total". The bar publishes its chips here; the dialog
 * reads them.
 */
const published = ref([]);

export function publishListFilters(chips) {
    published.value = chips;
}

export function clearListFilters() {
    published.value = [];
}

function words(key) {
    const text = String(key).replace(/_id$/, '').replace(/[_-]+/g, ' ').trim();

    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** "-total" → "Total, highest first". */
export function describeSort(value) {
    const descending = String(value).startsWith('-');
    const column = words(String(value).replace(/^-/, ''));

    return `${column}, ${descending ? 'highest or newest first' : 'lowest or oldest first'}`;
}

/**
 * The lines the export dialog shows for the current query string.
 *
 * A key the filter bar knows is shown as its chip. One it does not know — a link that arrived
 * with `?customer=12` on a page with no such filter field — is still shown, in readable words,
 * because it still narrows the export.
 *
 * @param {Array<[string, string]>} entries  query string pairs
 * @param {Array<{key: string, label: string, value: string}>} chips
 */
export function describeFilters(entries, chips = published.value) {
    return entries
        .filter(([key, value]) => value !== '' && !['page', 'per_page'].includes(key))
        .map(([key, value]) => {
            if (key === 'sort') return { key, label: 'Sorted by', value: describeSort(value) };

            const chip = chips.find((candidate) => candidate.key === key);

            if (chip) return { key, label: chip.label, value: chip.value };
            if (key === 'q') return { key, label: 'Search', value: `“${value}”` };

            return { key, label: words(key), value: words(value) };
        });
}
