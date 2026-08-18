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

const props = defineProps({
    adjustments: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    warehouses: { type: Array, default: () => [] },
});

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'warehouse', label: 'Warehouse' },
    { key: 'reason', label: 'Reason' },
    { key: 'adjusted_on', label: 'Date', sort: true },
    { key: 'creator', label: 'Created by' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Stock adjustments" />

        <template #title>Stock adjustments</template>
        <template #subtitle>Corrections against existing lots. Positive adds; negative writes off. Posted documents cannot be reversed.</template>

        <template #actions>
            <Button v-if="can('stock_adjustment.create')" variant="primary" href="/stock-adjustments/create">New adjustment</Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: ['draft','pending_approval','posted','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'warehouse', label: 'Warehouse', options: warehouses.map((w) => ({ value: w.id, label: w.name })) },
                ]"
                placeholder="Search number or reason…"
            />

            <DataTable :columns="columns" :rows="adjustments" row-key="id" :row-href="(row) => `/stock-adjustments/${row.id}`" empty="No adjustments.">
                <template #cell:number="{ row, value }">
                    <Link :href="`/stock-adjustments/${row.id}`" class="font-medium text-brand-700">{{ value ?? '(draft)' }}</Link>
                </template>
                <template #cell:reason="{ value }">
                    <span class="line-clamp-1 text-ink-700">{{ value }}</span>
                </template>
                <template #cell:adjusted_on="{ value }">{{ date(value) }}</template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="stock"
                        title="No stock adjustments"
                        description="A correction against an existing lot. Draft first — stock only moves when it is posted."
                        :action-label="can('stock_adjustment.create') ? 'New adjustment' : null"
                        action-href="/stock-adjustments/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
