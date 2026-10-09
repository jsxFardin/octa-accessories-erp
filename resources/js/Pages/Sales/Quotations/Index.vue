<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import ExportDialog from '@/Components/Ui/ExportDialog.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    quotations: Object,
    filters: Object,
    customers: Array,
    merchandisers: { type: Array, default: () => [] },
    /** Every quotation by status, regardless of the filters: the shape of the pipeline. */
    counts: { type: Object, default: () => ({}) },
});

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/quotations/${row.id}`) },
        { label: 'Edit', hidden: !can('quotation.update') || row.status !== 'draft', onSelect: () => router.visit(`/quotations/${row.id}/edit`) },
        {
            label: 'Duplicate',
            hidden: !can('quotation.create'),
            onSelect: () => router.post(`/quotations/${row.id}/duplicate`),
        },
    ];
}

/*
 * The pipeline as a strip of counts that double as filters. "Sent" is the one a merchandiser
 * works from: it is the money still on the table.
 */
const STAGES = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'revised', 'cancelled'];

const stages = computed(() => STAGES.map((status) => ({
    status,
    label: titleCase(status),
    count: props.counts[status] ?? 0,
    active: props.filters?.status === status,
})));

function filterStage(status) {
    const next = { ...props.filters, status: props.filters?.status === status ? '' : status, page: undefined };

    router.get('/quotations', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true });
}

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'customer', label: 'Customer' },
    { key: 'status', label: 'Status', sort: true },
    { key: 'merchandiser', label: 'Handled by' },
    { key: 'quotation_date', label: 'Date', sort: true },
    { key: 'valid_until', label: 'Valid until', sort: true },
    { key: 'total_qty', label: 'Qty', align: 'right' },
    { key: 'total', label: 'Value', align: 'right', sort: true },
];

const days = (n) => `${pcs(Math.abs(n))} ${Math.abs(n) === 1 ? 'day' : 'days'}`;

/*
 * Valid-until as an offer's life, not a date: "expires in 3 days" is the thing to act on,
 * and a sent quotation past its date reads as the problem it is until the nightly run
 * marks it expired.
 */
function validity(row) {
    if (!row.valid_until) return { text: 'Open', tone: 'text-ink-400' };
    if (row.days_to_expiry === null || row.days_to_expiry === undefined) return { text: date(row.valid_until), tone: '' };
    if (row.days_to_expiry < 0) return { text: `${date(row.valid_until)} · expired ${days(row.days_to_expiry)} ago`, tone: 'font-medium text-rose-700' };
    if (row.days_to_expiry === 0) return { text: `${date(row.valid_until)} · expires today`, tone: 'font-medium text-amber-700' };
    if (row.days_to_expiry <= 7) return { text: `${date(row.valid_until)} · ${days(row.days_to_expiry)} left`, tone: 'text-amber-700' };

    return { text: `${date(row.valid_until)} · ${days(row.days_to_expiry)} left`, tone: '' };
}
</script>

<template>
    <AppLayout>
        <Head title="Quotations" />

        <template #title>Quotations</template>
        <template #subtitle>Once a quotation is sent, it and its costing can no longer be changed</template>

        <template #actions>
            <ExportDialog v-if="can('quotation.export')" resource="quotations" />
            <Button v-if="can('quotation.create')" variant="primary" href="/quotations/create">New quotation</Button>
        </template>

        <nav aria-label="Quotations by stage" class="mb-4 grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-7">
            <button
                v-for="stage in stages"
                :key="stage.status"
                type="button"
                class="rounded-lg border px-3 py-2 text-left transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                :class="stage.active
                    ? 'border-brand-400 bg-brand-50 ring-1 ring-brand-400'
                    : 'border-slate-200 bg-white hover:border-brand-300'"
                :aria-pressed="stage.active"
                @click="filterStage(stage.status)"
            >
                <span class="block text-xs text-ink-500">{{ stage.label }}</span>
                <span
                    class="mt-0.5 block text-lg leading-tight font-semibold tnum"
                    :class="['rejected', 'expired'].includes(stage.status) && stage.count ? 'text-rose-700' : stage.status === 'sent' && stage.count ? 'text-brand-700' : 'text-ink-900'"
                >
                    {{ pcs(stage.count) }}
                </span>
            </button>
        </nav>

        <Card :padded="false">
            <FilterBar
                :only="['quotations', 'filters']"
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: STAGES.map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: c.id, label: c.name, code: c.code })) },
                    { key: 'merchandiser', label: 'Handled by', options: merchandisers.map((m) => ({ value: m.id, label: m.name })) },
                ]"
                placeholder="Search number or customer…"
            />

            <DataTable
                :only="['quotations', 'filters']"
                :columns="columns"
                :rows="quotations"
                row-key="id" :actions="rowActions" :row-href="(row) => `/quotations/${row.id}`"
                empty="No quotations match these filters."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}<span v-if="row.revision_no" class="text-ink-400">/R{{ row.revision_no }}</span></span></template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #cell:merchandiser="{ value }"><span :class="value ? '' : 'text-ink-400'">{{ value ?? '—' }}</span></template>
                <template #cell:quotation_date="{ value }">{{ date(value) }}</template>
                <template #cell:valid_until="{ row }">
                    <span :class="validity(row).tone">{{ validity(row).text }}</span>
                </template>
                <template #cell:total_qty="{ value }"><span class="tnum">{{ pcs(value) }} pcs</span></template>
                <template #cell:total="{ row, value }"><span class="tnum">{{ money(value, row.currency) }}</span></template>
                <template #empty>
                    <EmptyState
                        icon="quote"
                        title="No quotations yet"
                        description="A quotation prices an inquiry from a cost sheet, and keeps that costing as it was when the quotation was sent."
                        :action-label="can('quotation.create') ? 'New quotation' : null"
                        action-href="/quotations/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
