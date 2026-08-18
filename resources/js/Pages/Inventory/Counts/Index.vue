<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    counts: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    warehouses: { type: Array, default: () => [] },
});

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'warehouse', label: 'Warehouse' },
    { key: 'counted_on', label: 'Date', sort: true },
    { key: 'creator', label: 'Created by' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Physical counts" />

        <template #title>Physical counts</template>
        <template #subtitle>Warehouse-wide blind counts. Available lots freeze while counting; variances post when approved.</template>

        <template #actions>
            <Button v-if="can('physical_count.create')" variant="primary" href="/physical-counts/create">New count</Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: ['open','counting','reconciled','posted','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'warehouse', label: 'Warehouse', options: warehouses.map((w) => ({ value: w.id, label: w.name })) },
                ]"
                placeholder="Search number…"
            />

            <DataTable :columns="columns" :rows="counts" row-key="id" :row-href="(row) => `/physical-counts/${row.id}`" empty="No counts.">
                <template #cell:number="{ row, value }">
                    <Link :href="`/physical-counts/${row.id}`" class="font-medium text-brand-700">{{ value ?? '(open)' }}</Link>
                </template>
                <template #cell:counted_on="{ value }">{{ date(value) }}</template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="stock"
                        title="No physical counts"
                        description="Open a warehouse count, freeze available lots, enter blind quantities, reconcile, then post variances."
                        :action-label="can('physical_count.create') ? 'New count' : null"
                        action-href="/physical-counts/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
