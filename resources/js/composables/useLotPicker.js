import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * A lot picker that asks the server, one warehouse at a time.
 *
 * The form used to be given the first 400 lots across every warehouse and searched them in the
 * browser; past that many, a lot was simply absent and nothing said so. Now the page holds the
 * lots of the chosen warehouse, a search term goes to the server, and the picker says when what
 * it shows is only part of what there is.
 *
 * Lots already on the document are remembered here whatever the list is narrowed to, so a row
 * keeps its lot number and balance after the search moves on.
 *
 * @param {object} options
 * @param {() => Array} options.lots       the page's `lots` prop
 * @param {() => object|null} options.meta the page's `lotsMeta` prop: `{ shown, total, limit }`
 * @param {() => number|string} options.warehouse  the warehouse the lots come from
 * @param {() => Array<number>} options.keep       lot ids on the document's lines
 */
export function useLotPicker({ lots, meta, warehouse, keep }) {
    /** Every lot this page has been sent, by id. */
    const known = reactive(new Map());

    watch(lots, (rows) => rows.forEach((lot) => known.set(Number(lot.id), lot)), { immediate: true });

    function lotOf(line) {
        return known.get(Number(line.lot_id)) ?? null;
    }

    const search = ref('');
    const loading = ref(false);
    let timer = null;

    function reload() {
        const query = { warehouse: warehouse() || undefined, lot_search: search.value.trim() || undefined, keep: keep() };

        loading.value = true;

        router.get(window.location.pathname, query, {
            only: ['lots', 'lotsMeta'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => { loading.value = false; },
        });
    }

    watch(search, () => {
        clearTimeout(timer);
        timer = setTimeout(reload, 300);
    });

    // A different warehouse is a different list; the old search term does not carry over.
    watch(warehouse, () => {
        clearTimeout(timer);
        search.value = '';
        reload();
    });

    /** "Showing 200 of 1,240" — only when the list is partial. */
    const partial = computed(() => {
        const info = meta();

        return info && info.total > info.shown ? info : null;
    });

    return { lotOf, known, search, loading, partial };
}
