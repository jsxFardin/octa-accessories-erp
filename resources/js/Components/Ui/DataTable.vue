<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import DropdownMenu from '@/Components/Ui/DropdownMenu.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Pagination from '@/Components/Ui/Pagination.vue';

const props = defineProps({
    /** `[{ key, label, align, width, class, sort }]` — `sort` names the server-side column. */
    columns: { type: Array, required: true },
    /** A Laravel paginator payload, or a plain array. */
    rows: { type: [Array, Object], required: true },
    rowKey: { type: String, default: 'id' },
    /** Called with a row; returns a URL. Makes the whole row navigable. */
    rowHref: { type: Function, default: null },
    /**
     * Called with a row; returns DropdownMenu items. Renders the three-dot column, which is
     * excluded from the row link — otherwise opening the menu navigates away.
     */
    actions: { type: Function, default: null },
    empty: { type: String, default: 'Nothing here yet.' },
    dense: { type: Boolean, default: false },
    /** Renders skeleton rows instead of content — for lists that load after the page. */
    loading: { type: Boolean, default: false },
});

const page = usePage();

function items(rows) {
    return Array.isArray(rows) ? rows : (rows?.data ?? []);
}

function alignClass(column) {
    return {
        right: 'text-right tnum',
        center: 'text-center',
    }[column.align] ?? 'text-left';
}

function rowActions(row) {
    return (props.actions?.(row) ?? []).filter((item) => item && !item.hidden);
}

// --- Sorting -----------------------------------------------------------------------------
// The server has always accepted `?sort=-total`; until now nothing rendered a control for it,
// so every `sortable:` list in every controller was unreachable from the UI.
const currentSort = computed(() => {
    const query = new URLSearchParams(page.url.split('?')[1] ?? '');
    const value = query.get('sort') ?? '';

    return {
        column: value.replace(/^-/, ''),
        direction: value.startsWith('-') ? 'desc' : 'asc',
        applied: value !== '',
    };
});

function sortKey(column) {
    return column.sort === true ? column.key : column.sort;
}

function sortState(column) {
    const key = sortKey(column);

    if (!key || currentSort.value.column !== key) return null;

    return currentSort.value.direction;
}

function toggleSort(column) {
    const key = sortKey(column);

    if (!key) return;

    // First click sorts ascending, second flips. Sorting resets to page 1: staying on page 7
    // of a re-ordered list shows a different slice of data for no reason the user asked for.
    const next = sortState(column) === 'asc' ? `-${key}` : key;
    const query = new URLSearchParams(page.url.split('?')[1] ?? '');

    query.set('sort', next);
    query.delete('page');

    router.get(`${page.url.split('?')[0]}?${query}`, {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const skeletonRows = computed(() => Array.from({ length: 6 }, (_, index) => index));
</script>

<template>
    <div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50/80">
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            class="px-3 py-2 text-xs font-semibold whitespace-nowrap text-ink-600"
                            :class="[alignClass(column), column.class]"
                            :style="column.width ? { width: column.width } : undefined"
                            :aria-sort="sortState(column) ? (sortState(column) === 'asc' ? 'ascending' : 'descending') : undefined"
                        >
                            <button
                                v-if="sortKey(column)"
                                class="group inline-flex items-center gap-1 rounded transition hover:text-ink-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                :class="column.align === 'right' && 'flex-row-reverse'"
                                @click="toggleSort(column)"
                            >
                                {{ column.label }}
                                <Icon
                                    :name="sortState(column) === 'asc' ? 'up' : 'down'"
                                    size="size-3"
                                    :class="sortState(column) ? 'text-brand-600' : 'text-ink-300 opacity-0 transition group-hover:opacity-100'"
                                />
                            </button>
                            <template v-else>{{ column.label }}</template>
                        </th>

                        <th v-if="actions" scope="col" class="w-10 px-3 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 bg-white">
                    <!-- Skeletons keep the table's shape while it loads, so nothing jumps. -->
                    <template v-if="loading">
                        <tr v-for="index in skeletonRows" :key="`skeleton-${index}`">
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                class="px-3"
                                :class="dense ? 'py-2' : 'py-3'"
                            >
                                <span
                                    class="block h-3 animate-pulse rounded bg-slate-100"
                                    :style="{ width: `${40 + ((index * 17) % 45)}%` }"
                                />
                            </td>
                            <td v-if="actions" />
                        </tr>
                    </template>

                    <tr v-else-if="items(rows).length === 0">
                        <td :colspan="columns.length + (actions ? 1 : 0)" class="px-3 py-12 text-center">
                            <slot name="empty">
                                <p class="text-sm text-ink-500">{{ empty }}</p>
                            </slot>
                        </td>
                    </tr>

                    <tr
                        v-for="row in loading ? [] : items(rows)"
                        :key="row[rowKey]"
                        class="transition hover:bg-brand-50/40 focus-within:bg-brand-50/40"
                    >
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            class="px-3 whitespace-nowrap text-ink-700"
                            :class="[alignClass(column), dense ? 'py-1.5' : 'py-2.5']"
                        >
                            <component
                                :is="rowHref ? Link : 'div'"
                                v-bind="rowHref ? { href: rowHref(row) } : {}"
                                class="block rounded focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                            >
                                <slot :name="`cell:${column.key}`" :row="row" :value="row[column.key]">
                                    {{ row[column.key] ?? '—' }}
                                </slot>
                            </component>
                        </td>

                        <td v-if="actions" class="px-3 text-right" :class="dense ? 'py-1.5' : 'py-2.5'">
                            <DropdownMenu v-if="rowActions(row).length" :items="rowActions(row)" />
                        </td>
                    </tr>
                </tbody>

                <tfoot v-if="$slots.footer" class="border-t-2 border-slate-200 bg-slate-50 font-medium">
                    <slot name="footer" />
                </tfoot>
            </table>
        </div>

        <Pagination v-if="!Array.isArray(rows)" :meta="rows" />
    </div>
</template>
