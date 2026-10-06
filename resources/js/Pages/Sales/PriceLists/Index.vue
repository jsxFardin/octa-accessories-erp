<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, pcs } from '@/plugins/formatting';
import { useConfirm } from '@/composables/useConfirm';
import { can } from '@/plugins/permissions';

const props = defineProps({
    lists: Object,
    filters: Object,
    customers: { type: Array, default: () => [] },
    /** Every list by standing today, regardless of the filters. */
    counts: { type: Object, default: () => ({}) },
});

const { confirm } = useConfirm();

function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/price-lists/${row.id}`) },
        { label: 'Edit', hidden: !can('price_list.update'), onSelect: () => router.visit(`/price-lists/${row.id}/edit`) },
        {
            label: 'Deactivate',
            tone: 'danger',
            hidden: !can('price_list.delete') || !row.is_active,
            onSelect: async () => {
                if (await confirm({
                    title: `Deactivate ${row.code}?`,
                    message: 'New quotations will stop reading its rates. Quotations already priced from it keep theirs. It can be reactivated later.',
                    confirmLabel: 'Deactivate',
                })) {
                    router.delete(`/price-lists/${row.id}`, { preserveScroll: true });
                }
            },
        },
        {
            // The way back. There was none: a list deactivated by mistake had to be recreated.
            label: 'Reactivate',
            hidden: !can('price_list.update') || Boolean(row.is_active),
            onSelect: () => router.post(`/price-lists/${row.id}/reactivate`, {}, { preserveScroll: true }),
        },
    ];
}

/*
 * A list's standing today is what a merchandiser checks before quoting. The dates said when;
 * nothing said whether. The strip counts them and filters by them.
 */
const STANDINGS = [
    { key: 'current', label: 'Current', tone: 'success' },
    { key: 'ending', label: 'Ending within 30 days', tone: 'warning' },
    { key: 'upcoming', label: 'Starts later', tone: 'info' },
    { key: 'lapsed', label: 'Lapsed', tone: 'danger' },
    { key: 'inactive', label: 'Inactive', tone: 'neutral' },
];

const standingOf = (key) => STANDINGS.find((s) => s.key === key) ?? STANDINGS[0];

const stages = computed(() => STANDINGS.map((s) => ({
    ...s,
    count: props.counts[s.key] ?? 0,
    active: props.filters?.standing === s.key,
})));

function filterStanding(key) {
    const next = { ...props.filters, standing: props.filters?.standing === key ? '' : key, page: undefined };

    router.get('/price-lists', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true });
}

const columns = [
    { key: 'code', label: 'Code', sort: true },
    { key: 'name', label: 'Name', sort: true },
    { key: 'customer', label: 'Customer', sort: true },
    { key: 'standing', label: 'Standing' },
    { key: 'valid_from', label: 'From', sort: true },
    { key: 'valid_to', label: 'To', sort: true },
    { key: 'products_count', label: 'Products', align: 'right' },
    { key: 'currency', label: 'Currency' },
];
</script>

<template>
    <AppLayout>
        <Head title="Price lists" />

        <template #title>Price lists</template>
        <template #subtitle>Rates agreed with a customer, by quantity break. A quotation reads the list that is current on its date.</template>

        <template #actions>
            <Button v-if="can('price_list.create')" variant="primary" href="/price-lists/create">New price list</Button>
        </template>

        <nav aria-label="Price lists by standing" class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-5">
            <button
                v-for="stage in stages"
                :key="stage.key"
                type="button"
                class="rounded-lg border px-3 py-2 text-left transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                :class="stage.active ? 'border-brand-400 bg-brand-50 ring-1 ring-brand-400' : 'border-slate-200 bg-white hover:border-brand-300'"
                :aria-pressed="stage.active"
                @click="filterStanding(stage.key)"
            >
                <span class="block text-xs text-ink-500">{{ stage.label }}</span>
                <span
                    class="mt-0.5 block text-lg leading-tight font-semibold tnum"
                    :class="stage.count && stage.tone === 'danger' ? 'text-rose-700' : stage.count && stage.tone === 'warning' ? 'text-amber-700' : 'text-ink-900'"
                >
                    {{ pcs(stage.count) }}
                </span>
            </button>
        </nav>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: c.id, label: c.name, code: c.code })) },
                    { key: 'standing', label: 'Standing', options: STANDINGS.map((s) => ({ value: s.key, label: s.label })) },
                ]"
                placeholder="Search code, name or customer…"
            />

            <DataTable
                :columns="columns"
                :rows="lists"
                row-key="id"
                :row-href="(row) => `/price-lists/${row.id}`"
                :actions="rowActions"
            >
                <template #cell:code="{ value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:standing="{ value }"><Badge :tone="standingOf(value).tone" :label="standingOf(value).label" /></template>
                <template #cell:valid_from="{ value }">{{ date(value) }}</template>
                <template #cell:valid_to="{ value }"><span :class="value ? '' : 'text-ink-500'">{{ value ? date(value) : 'Open-ended' }}</span></template>
                <template #cell:products_count="{ row, value }">
                    <span class="tnum">{{ pcs(value) }}</span>
                    <span class="text-xs text-ink-500"> · {{ pcs(row.lines_count) }} {{ row.lines_count === 1 ? 'break' : 'breaks' }}</span>
                </template>

                <template #empty>
                    <EmptyState
                        icon="card"
                        title="No price lists yet"
                        description="A price list fixes the rate for a customer's products by quantity break, so a repeat order is not re-costed from scratch each time."
                        :action-label="can('price_list.create') ? 'New price list' : null"
                        action-href="/price-lists/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
