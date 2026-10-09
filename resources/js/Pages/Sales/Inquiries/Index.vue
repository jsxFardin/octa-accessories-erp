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
    inquiries: Object,
    filters: Object,
    customers: Array,
    merchandisers: { type: Array, default: () => [] },
    /** Every inquiry by status, regardless of the filters: the shape of the pipeline. */
    counts: { type: Object, default: () => ({}) },
});

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/inquiries/${row.id}`) },
        { label: 'Edit', hidden: !can('inquiry.update') || !(row.status === 'draft'), onSelect: () => router.visit(`/inquiries/${row.id}/edit`) },
    ];
}

/*
 * The pipeline, as one strip of counts that double as filters. A merchandiser's first
 * question of the list is "how many are waiting on me", not "show me everything".
 */
const STAGES = ['draft', 'open', 'quoted', 'won', 'lost', 'cancelled'];

const stages = computed(() => STAGES.map((status) => ({
    status,
    label: titleCase(status),
    count: props.counts[status] ?? 0,
    active: props.filters?.status === status,
})));

function filterStage(status) {
    const next = { ...props.filters, status: props.filters?.status === status ? '' : status, page: undefined };

    router.get('/inquiries', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true });
}

// Status third, not last: on a 1280 px screen the last column is the one that scrolls out
// of view, and the stage is the thing a merchandiser scans the list for.
const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'customer', label: 'Customer' },
    { key: 'status', label: 'Status', sort: true },
    { key: 'merchandiser', label: 'Handled by' },
    { key: 'inquiry_date', label: 'Received', sort: true },
    { key: 'required_by', label: 'Required by', sort: true },
    { key: 'total_qty', label: 'Qty', align: 'right' },
    { key: 'value', label: 'Value', align: 'right' },
];

const days = (n) => `${pcs(Math.abs(n))} ${Math.abs(n) === 1 ? 'day' : 'days'}`;
</script>

<template>
    <AppLayout>
        <Head title="Inquiries" />

        <template #title>Inquiries</template>
        <template #subtitle>What customers have asked for. An inquiry gets its number when it is submitted.</template>

        <template #actions>
            <ExportDialog v-if="can('inquiry.export')" resource="inquiries" />
            <Button v-if="can('inquiry.create')" variant="primary" href="/inquiries/create">New inquiry</Button>
        </template>

        <nav aria-label="Inquiries by stage" class="mb-4 grid grid-cols-3 gap-2 sm:grid-cols-6">
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
                <span class="mt-0.5 block text-lg leading-tight font-semibold tnum" :class="stage.status === 'lost' && stage.count ? 'text-rose-700' : 'text-ink-900'">
                    {{ pcs(stage.count) }}
                </span>
            </button>
        </nav>

        <Card :padded="false">
            <FilterBar
                :only="['inquiries', 'filters']"
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: STAGES.map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: c.id, label: c.name, code: c.code })) },
                    { key: 'merchandiser', label: 'Handled by', options: merchandisers.map((m) => ({ value: m.id, label: m.name })) },
                ]"
                placeholder="Search number, customer or notes…"
            />

            <DataTable
                :only="['inquiries', 'filters']"
                :columns="columns"
                :rows="inquiries"
                row-key="id" :actions="rowActions" :row-href="(row) => `/inquiries/${row.id}`"
                empty="No inquiries match these filters."
            >
                <template #cell:number="{ value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:merchandiser="{ value }"><span :class="value ? '' : 'text-ink-400'">{{ value ?? '—' }}</span></template>
                <template #cell:inquiry_date="{ row, value }">
                    {{ date(value) }}
                    <span v-if="row.age_days >= 1 && ['draft', 'open', 'quoted'].includes(row.status)" class="ml-1 text-xs text-ink-500">· {{ days(row.age_days) }} ago</span>
                </template>
                <!-- A date that has passed on something still open is the fact, not the date. -->
                <template #cell:required_by="{ row, value }">
                    <span v-if="!value" class="text-ink-400">Open</span>
                    <span v-else :class="row.overdue ? 'font-medium text-rose-700' : ''">
                        {{ date(value) }}<span v-if="row.overdue" class="text-xs"> · {{ days(row.days_to_required) }} overdue</span>
                    </span>
                </template>
                <template #cell:total_qty="{ value }"><span class="tnum">{{ pcs(value) }} pcs</span></template>
                <!-- The customer's own target × quantity, in the currency they trade in — not a price. -->
                <template #cell:value="{ row, value }">
                    <span v-if="Number(value) > 0" class="tnum">{{ money(value, row.currency) }}</span>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="inbox"
                        title="No inquiries yet"
                        description="An inquiry is the front of the funnel: what a customer asked for, before it has been costed."
                        :action-label="can('inquiry.create') ? 'New inquiry' : null"
                        action-href="/inquiries/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
