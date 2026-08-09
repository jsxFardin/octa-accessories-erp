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

const props = defineProps({ purchase_requisitions: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number' },
    { key: 'required_date', label: 'Required' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Purchase requisitions" />

        <template #title>Purchase requisitions</template>
        <template #subtitle>Shortages raised by an MRP run arrive here (BR-24)</template>

        <template #actions>
            <span />
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','submitted','approved','converted','rejected','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search requisition number…" />

            <DataTable
                :columns="columns"
                :rows="purchase_requisitions"
                row-key="id"
                empty="No requisitions raised."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-slate-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:required_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
