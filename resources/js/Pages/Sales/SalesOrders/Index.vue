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
import { date, money, pcs, pct, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    orders: Object,
    filters: Object,
    customers: Array,
    merchandisers: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    /** Every order by status, plus `late`, regardless of the filters: the shape of the book. */
    counts: { type: Object, default: () => ({}) },
});

/**
 * Built per row so the menu never offers what this user may not do, or the record will not
 * allow.
 *
 * F-08 — the dashboard queue "Confirmed orders with no job card" lands here, and the menu
 * offered Open and Edit: the one thing the queue exists for was not on it, so clearing the
 * queue meant opening the order, finding the line, and navigating to planning by hand. The
 * action prefills from the order server-side (`JobCardController::create()`), which is also
 * where the permission is enforced — this only decides whether to show it.
 */
function rowActions(row) {
    return [
        {
            label: 'Create job card',
            hidden: !can('job_card.create') || !row.awaits_job_card,
            onSelect: () => router.visit(`/job-cards/create?sales_order=${row.id}`),
        },
        { label: 'Open', onSelect: () => router.visit(`/sales-orders/${row.id}`) },
        { label: 'Edit', hidden: !can('sales_order.update') || ['closed', 'cancelled'].includes(row.status), onSelect: () => router.visit(`/sales-orders/${row.id}/edit`) },
    ];
}

/*
 * The book as a strip: how many at each stage, and how many are late. Late leads because it
 * is the one count that is a problem by itself; each tile is a filter.
 */
const STAGES = ['draft', 'credit_hold', 'confirmed', 'in_production', 'partially_delivered', 'delivered', 'closed', 'cancelled'];

const stages = computed(() => [
    { key: 'late', label: 'Late', count: props.counts.late ?? 0, active: props.filters?.late === '1', tone: 'danger' },
    ...STAGES.map((status) => ({
        key: status,
        label: titleCase(status),
        count: props.counts[status] ?? 0,
        active: props.filters?.status === status && props.filters?.late !== '1',
        tone: status === 'credit_hold' && props.counts[status] ? 'warning' : 'neutral',
    })),
]);

function filterStage(key) {
    const next = { ...props.filters, page: undefined };

    if (key === 'late') {
        next.late = props.filters?.late === '1' ? '' : '1';
        next.status = '';
    } else {
        next.status = props.filters?.status === key ? '' : key;
        next.late = '';
    }

    router.get('/sales-orders', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true });
}

// Status and Due sit beside the number: they are what the eye scans for, and on a
// narrow screen the rightmost columns are the first to fall off the edge.
const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'status', label: 'Status', sort: true },
    { key: 'delivery_date', label: 'Due', sort: true },
    { key: 'customer', label: 'Customer' },
    { key: 'priority', label: 'Priority', sort: true },
    { key: 'merchandiser', label: 'Handled by' },
    { key: 'delivered_pct', label: 'Delivered', align: 'right', width: '9rem' },
    { key: 'total', label: 'Value', align: 'right', sort: true },
];

const days = (n) => `${pcs(Math.abs(n))} ${Math.abs(n) === 1 ? 'day' : 'days'}`;

/** The due date as a deadline: how late, how soon, or just when. */
function due(row) {
    if (!row.delivery_date) return { text: '—', tone: 'text-ink-400' };
    if (row.overdue) return { text: `${date(row.delivery_date)} · ${days(row.days_to_due)} overdue`, tone: 'font-medium text-rose-700' };
    if (row.days_to_due !== null && row.days_to_due <= 7 && ['confirmed', 'in_production', 'partially_delivered'].includes(row.status)) {
        return { text: `${date(row.delivery_date)} · ${row.days_to_due === 0 ? 'today' : `in ${days(row.days_to_due)}`}`, tone: 'text-amber-700' };
    }

    return { text: date(row.delivery_date), tone: '' };
}

const PRIORITY_TONES = { urgent: 'danger', high: 'warning', normal: 'neutral', low: 'neutral' };
</script>

<template>
    <AppLayout>
        <Head title="Sales orders" />

        <template #title>Sales orders</template>
        <template #subtitle>Confirmed orders need a current spec and an approved artwork on every line</template>

        <template #actions>
            <ExportDialog v-if="can('sales_order.export')" resource="sales-orders" />
            <Button v-if="can('sales_order.create')" variant="primary" href="/sales-orders/create">New order</Button>
        </template>

        <nav aria-label="Orders by stage" class="mb-4 grid grid-cols-3 gap-2 sm:grid-cols-5 xl:grid-cols-9">
            <button
                v-for="stage in stages"
                :key="stage.key"
                type="button"
                class="rounded-lg border px-3 py-2 text-left transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                :class="stage.active
                    ? 'border-brand-400 bg-brand-50 ring-1 ring-brand-400'
                    : stage.tone === 'danger' && stage.count ? 'border-rose-200 bg-rose-50/60 hover:border-rose-300' : 'border-slate-200 bg-white hover:border-brand-300'"
                :aria-pressed="stage.active"
                @click="filterStage(stage.key)"
            >
                <span class="block text-xs" :class="stage.tone === 'danger' && stage.count ? 'text-rose-800' : 'text-ink-500'">{{ stage.label }}</span>
                <span
                    class="mt-0.5 block text-lg leading-tight font-semibold tnum"
                    :class="stage.tone === 'danger' && stage.count ? 'text-rose-700' : stage.tone === 'warning' ? 'text-amber-700' : 'text-ink-900'"
                >
                    {{ pcs(stage.count) }}
                </span>
            </button>
        </nav>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: STAGES.map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: c.id, label: c.name, code: c.code })) },
                    { key: 'merchandiser', label: 'Handled by', options: merchandisers.map((m) => ({ value: m.id, label: m.name })) },
                    { key: 'priority', label: 'Priority', options: priorities },
                    { key: 'late', label: 'Deadline', options: [{ value: '1', label: 'Past due' }] },
                    { key: 'awaiting', label: 'Next step', options: [{ value: 'job_card', label: 'Needs a job card' }] },
                ]"
                placeholder="Search number, customer or PO…"
            />

            <DataTable
                :columns="columns"
                :rows="orders"
                row-key="id" :actions="rowActions" :row-href="(row) => `/sales-orders/${row.id}`"
                empty="No orders match these filters."
            >
                <template #cell:number="{ row, value }">
                    <span class="doc-link-quiet">{{ value ?? "(unnumbered)" }}<span v-if="row.revision_no" class="text-ink-400">/R{{ row.revision_no }}</span></span>
                    <span v-if="row.customer_po_no" class="block text-xs text-ink-500">PO {{ row.customer_po_no }}</span>
                </template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #cell:delivery_date="{ row }"><span :class="due(row).tone">{{ due(row).text }}</span></template>
                <template #cell:priority="{ value }">
                    <Badge v-if="value && value !== 'normal'" :tone="PRIORITY_TONES[value] ?? 'neutral'" :label="titleCase(value)" />
                    <span v-else class="text-ink-500">{{ value ? titleCase(value) : '—' }}</span>
                </template>
                <template #cell:merchandiser="{ value }"><span :class="value ? '' : 'text-ink-400'">{{ value ?? '—' }}</span></template>
                <!-- Delivered against ordered, as a bar: the one figure that says how far along the order is. -->
                <template #cell:delivered_pct="{ row, value }">
                    <span class="inline-flex items-center justify-end gap-2">
                        <span class="h-1.5 w-14 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                            <span
                                class="block h-full rounded-full"
                                :class="Number(value) >= 100 ? 'bg-emerald-500' : 'bg-brand-500'"
                                :style="{ width: `${Math.min(100, Number(value) || 0)}%` }"
                            />
                        </span>
                        <span class="w-9 text-right tnum" :title="`${pcs(row.delivered_qty)} of ${pcs(row.ordered_qty)} pcs`">{{ pct(value, 0) }}</span>
                    </span>
                </template>
                <template #cell:total="{ row, value }"><span class="tnum">{{ money(value, row.currency) }}</span></template>
                <template #empty>
                    <EmptyState
                        icon="order"
                        title="No sales orders yet"
                        description="An order cannot be confirmed without a current spec and an approved artwork on every line."
                        :action-label="can('sales_order.create') ? 'New order' : null"
                        action-href="/sales-orders/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
