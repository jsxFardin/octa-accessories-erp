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
    transfers: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    warehouses: { type: Array, default: () => [] },
});

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'from_warehouse', label: 'From' },
    { key: 'to_warehouse', label: 'To' },
    { key: 'transfer_date', label: 'Date', sort: true },
    { key: 'creator', label: 'Created by' },
    { key: 'status', label: 'Status', sort: true },
];

const warehouseOptions = props.warehouses.map((w) => ({ value: w.id, label: w.name }));
</script>

<template>
    <AppLayout>
        <Head title="Stock transfers" />

        <template #title>Stock transfers</template>
        <template #subtitle>Move existing lots between warehouses. Dispatch posts into transit; receive posts into the destination.</template>

        <template #actions>
            <Button v-if="can('stock_transfer.create')" variant="primary" href="/stock-transfers/create">New transfer</Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: ['draft','in_transit','received','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'from_warehouse', label: 'From', options: warehouseOptions },
                    { key: 'to_warehouse', label: 'To', options: warehouseOptions },
                ]"
                placeholder="Search number or remarks…"
            />

            <DataTable :columns="columns" :rows="transfers" row-key="id" :row-href="(row) => `/stock-transfers/${row.id}`" empty="No transfers.">
                <template #cell:number="{ row, value }">
                    <Link :href="`/stock-transfers/${row.id}`" class="font-medium text-brand-700">{{ value ?? '(draft)' }}</Link>
                </template>
                <template #cell:transfer_date="{ value }">{{ date(value) }}</template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="stock"
                        title="No stock transfers"
                        description="Draft a transfer first. Stock only moves when it is dispatched into transit."
                        :action-label="can('stock_transfer.create') ? 'New transfer' : null"
                        action-href="/stock-transfers/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
