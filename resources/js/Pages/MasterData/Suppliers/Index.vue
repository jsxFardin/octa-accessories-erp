<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ suppliers: Object, filters: Object });

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'country', label: 'Country' },
    { key: 'lead_time_days', label: 'Lead days', align: 'right' },
    { key: 'rating', label: 'Rating', align: 'right' },
    { key: 'is_approved', label: 'Approved' },
    { key: 'is_active', label: 'Active' },
];
</script>

<template>
    <AppLayout>
        <Head title="Suppliers" />

        <template #title>Suppliers</template>
        <template #subtitle>Yarn, ribbon, ink and chemicals — lead time is per supplier-item (BR-26)</template>

        <template #actions>
            <Button v-if="can('supplier.create')" variant="primary" href="/suppliers/create">New supplier</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'approved', label: 'Approved', options: [{ value: '1', label: 'Approved' }, { value: '0', label: 'Not approved' }] }]" placeholder="Search code, name or country…" />

            <DataTable
                :columns="columns"
                :rows="suppliers"
                row-key="id" :row-href="(row) => `/suppliers/${row.id}`"
                empty="No suppliers match these filters."
            >
                <template #cell:code="{ row, value }"><span class="font-medium text-slate-900">{{ value }}</span></template>
                <template #cell:is_approved="{ row, value }"><Badge :tone="value ? 'success' : 'warning'" :label="value ? 'Approved' : 'Pending'" /></template>
                <template #cell:is_active="{ row, value }"><Badge :tone="value ? 'success' : 'neutral'" :label="value ? 'Active' : 'Inactive'" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
