<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    ncrs: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    owners: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const columns = [
    { key: 'number', label: 'NCR', sort: true },
    { key: 'status', label: 'Status', sort: true },
    { key: 'severity', label: 'Severity', sort: true },
    { key: 'source', label: 'Source' },
    { key: 'job_card', label: 'Job card' },
    { key: 'product', label: 'Product' },
    { key: 'inspection', label: 'Inspection' },
    { key: 'owner', label: 'Owner' },
    { key: 'disposition', label: 'Disposition' },
    { key: 'raised_on', label: 'Raised', sort: true },
    { key: 'age_days', label: 'Age' },
];

const summary = [
    { key: 'open', label: 'Open' },
    { key: 'investigating', label: 'Investigating' },
    { key: 'action_taken', label: 'Awaiting verification' },
    { key: 'overdue', label: 'Overdue CAPA' },
    { key: 'closed', label: 'Closed' },
];
</script>

<template>
    <AppLayout>
        <Head title="NCRs" />

        <template #title>Non-conformance reports</template>
        <template #subtitle>
            Raised automatically when QC rejects a lot. Investigation and CAPA close the record; they do not rewrite stock.
        </template>

        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div
                v-for="item in summary"
                :key="item.key"
                class="rounded-lg border border-slate-200 bg-white px-3 py-2 shadow-sm"
            >
                <dt class="text-[11px] text-ink-500">{{ item.label }}</dt>
                <dd class="text-lg font-semibold tnum" :class="item.key === 'overdue' && counts.overdue ? 'text-rose-700' : 'text-ink-900'">
                    {{ counts[item.key] ?? 0 }}
                </dd>
            </div>
        </div>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: ['open','investigating','action_taken','verified','closed'].map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'severity', label: 'Severity', options: ['critical','major','minor'].map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'disposition', label: 'Disposition', options: ['rework','concession','downgrade','scrap'].map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'source', label: 'Source', options: ['incoming','in_process','final','customer_complaint','audit','lab'].map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'owner', label: 'Owner', options: owners.map((u) => ({ value: u.id, label: u.name })) },
                ]"
                placeholder="Search NCR number…"
            />

            <DataTable :columns="columns" :rows="ncrs" row-key="id" :row-href="(row) => `/ncrs/${row.id}`" empty="No NCRs match these filters.">
                <template #cell:number="{ row, value }">
                    <Link :href="`/ncrs/${row.id}`" class="font-medium text-brand-700">{{ value }}</Link>
                </template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #cell:severity="{ value }">
                    <Badge :tone="value === 'critical' ? 'danger' : value === 'major' ? 'warning' : 'neutral'" :label="titleCase(value)" />
                </template>
                <template #cell:source="{ value }">{{ titleCase(value) }}</template>
                <template #cell:product="{ row }">{{ row.product?.code ?? '—' }}</template>
                <template #cell:disposition="{ value }">
                    <Badge v-if="value" tone="warning" :label="titleCase(value)" />
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:raised_on="{ value }">{{ date(value) }}</template>
                <template #cell:age_days="{ value }">{{ value == null ? '—' : `${Math.round(value)}d` }}</template>
                <template #empty>
                    <EmptyState
                        icon="inspection"
                        title="No NCRs"
                        description="A rejected QC inspection raises an NCR automatically. There is no hand-drafted NCR."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
