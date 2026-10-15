<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    requests: Object,
    filters: Object,
    customers: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    /** Every request by status, regardless of the filters. */
    counts: { type: Object, default: () => ({}) },
});

const STAGES = ['requested', 'in_development', 'in_production', 'ready', 'dispatched', 'approved', 'rejected'];

const stages = computed(() => STAGES.map((status) => ({
    status,
    label: titleCase(status),
    count: props.counts[status] ?? 0,
    active: props.filters?.status === status,
})));

function filterStage(status) {
    const next = { ...props.filters, status: props.filters?.status === status ? '' : status, page: undefined };

    router.get('/sample-requests', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true });
}

function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/sample-requests/${row.id}`) },
        { label: 'Edit', hidden: !can('sample_request.update') || !['requested', 'in_development'].includes(row.status), onSelect: () => router.visit(`/sample-requests/${row.id}/edit`) },
    ];
}

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'customer', label: 'Customer' },
    { key: 'sample_type', label: 'Type', sort: true },
    { key: 'status', label: 'Status', sort: true },
    { key: 'lines_count', label: 'Lines', align: 'right' },
    { key: 'required_by', label: 'Required by', sort: true },
    { key: 'tracking_no', label: 'Tracking' },
    { key: 'charge_amount', label: 'Charge', align: 'right' },
];

const days = (n) => `${pcs(Math.abs(n))} ${Math.abs(n) === 1 ? 'day' : 'days'}`;
</script>

<template>
    <AppLayout>
        <Head title="Samples" />

        <template #title>Samples</template>
        <template #subtitle>What the sample room has been asked to make, where each parcel is, and what the customer said.</template>

        <template #actions>
            <Button v-if="can('sample_request.create')" variant="primary" href="/sample-requests/create">New sample request</Button>
        </template>

        <nav aria-label="Samples by stage" class="mb-4 grid grid-cols-4 gap-2 sm:grid-cols-7">
            <button
                v-for="stage in stages"
                :key="stage.status"
                type="button"
                class="rounded-lg border px-3 py-2 text-left transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                :class="stage.active ? 'border-brand-400 bg-brand-50 ring-1 ring-brand-400' : 'border-slate-200 bg-white hover:border-brand-300'"
                :aria-pressed="stage.active"
                @click="filterStage(stage.status)"
            >
                <span class="block text-xs text-ink-500">{{ stage.label }}</span>
                <span class="mt-0.5 block text-lg leading-tight font-semibold tnum" :class="stage.status === 'rejected' && stage.count ? 'text-rose-700' : 'text-ink-900'">{{ pcs(stage.count) }}</span>
            </button>
        </nav>

        <Card :padded="false">
            <FilterBar
                :only="['requests', 'filters']"
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: STAGES.map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'type', label: 'Type', options: types.map((t) => ({ value: t, label: titleCase(t) })) },
                    { key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: c.id, label: c.name, code: c.code })) },
                ]"
                placeholder="Search number, customer or notes…"
            />

            <DataTable
                :only="['requests', 'filters']"
                :columns="columns"
                :rows="requests"
                row-key="id"
                :actions="rowActions"
                :row-href="(row) => `/sample-requests/${row.id}`"
                empty="No sample requests match these filters."
            >
                <template #cell:number="{ value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:sample_type="{ value }">{{ titleCase(value) }}</template>
                <template #cell:status="{ row, value }">
                    <Badge :status="value" />
                    <!-- AS-6 AC2 — the follow-up list: how long the customer has had it. -->
                    <span v-if="value === 'dispatched' && row.days_waiting !== null" class="ml-1 text-xs text-ink-500">· {{ days(row.days_waiting) }} with the customer</span>
                </template>
                <template #cell:lines_count="{ row, value }">
                    <span class="tnum">{{ pcs(value) }}</span>
                    <span v-if="row.status === 'dispatched' && row.decided_lines" class="text-xs text-ink-500"> · {{ pcs(row.decided_lines) }} decided</span>
                </template>
                <template #cell:required_by="{ row, value }">
                    <span v-if="!value" class="text-ink-400">Open</span>
                    <span v-else :class="row.overdue ? 'font-medium text-rose-700' : ''">{{ date(value) }}<span v-if="row.overdue" class="text-xs"> · overdue</span></span>
                </template>
                <template #cell:tracking_no="{ value }"><span :class="value ? 'font-mono text-xs text-ink-800' : 'text-ink-400'">{{ value ?? '—' }}</span></template>
                <template #cell:charge_amount="{ row, value }">
                    <span v-if="row.is_chargeable" class="tnum">{{ money(value) }}</span>
                    <span v-else class="text-xs text-ink-400">free</span>
                </template>
                <template #empty>
                    <EmptyState
                        icon="inbox"
                        title="No sample requests yet"
                        description="A sample request is what a merchandiser asks the sample room to make for a customer's approval, before bulk is run."
                        :action-label="can('sample_request.create') ? 'New sample request' : null"
                        action-href="/sample-requests/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
