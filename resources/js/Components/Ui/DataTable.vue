<script setup>
import { Link } from '@inertiajs/vue3';
import DropdownMenu from '@/Components/Ui/DropdownMenu.vue';
import Pagination from '@/Components/Ui/Pagination.vue';

const props = defineProps({
    /** `[{ key, label, align, width, class }]` */
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
});

function rowActions(row) {
    return (props.actions?.(row) ?? []).filter((item) => item && !item.hidden);
}

function items(rows) {
    return Array.isArray(rows) ? rows : (rows?.data ?? []);
}

function alignClass(column) {
    return {
        right: 'text-right tnum',
        center: 'text-center',
    }[column.align] ?? 'text-left';
}
</script>

<template>
    <div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            class="px-3 py-2 text-xs font-semibold whitespace-nowrap text-ink-700"
                            :class="[alignClass(column), column.class]"
                            :style="column.width ? { width: column.width } : undefined"
                        >
                            {{ column.label }}
                        </th>

                        <th v-if="actions" scope="col" class="w-10 px-3 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 bg-white">
                    <tr v-if="items(rows).length === 0">
                        <td :colspan="columns.length + (actions ? 1 : 0)" class="px-3 py-10 text-center text-sm text-ink-500">
                            {{ empty }}
                        </td>
                    </tr>

                    <tr
                        v-for="row in items(rows)"
                        :key="row[rowKey]"
                        class="transition hover:bg-brand-50/40"
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
                                class="block"
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
