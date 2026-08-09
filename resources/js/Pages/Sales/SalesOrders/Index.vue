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

const props = defineProps({ orders: Object, filters: Object, customers: Array });

const columns = [
    { key: 'number', label: 'Number' },
    { key: 'customer', label: 'Customer' },
    { key: 'customer_po_no', label: 'Customer PO' },
    { key: 'order_date', label: 'Ordered' },
    { key: 'delivery_date', label: 'Due' },
    { key: 'lines_count', label: 'Lines', align: 'center' },
    { key: 'total', label: 'Value', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Sales orders" />

        <template #title>Sales orders</template>
        <template #subtitle>Confirmed orders need a current spec and an approved artwork on every line (S3)</template>

        <template #actions>
            <Button v-if="can('sales_order.create')" variant="primary" href="/sales-orders/create">New order</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','credit_hold','confirmed','in_production','partially_delivered','delivered','closed','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }, { key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: c.id, label: c.name })) }]" placeholder="Search order number or customer PO…" />

            <DataTable
                :columns="columns"
                :rows="orders"
                row-key="id" :row-href="(row) => `/sales-orders/${row.id}`"
                empty="No orders match these filters."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-slate-900">{{ value ?? "(unnumbered)" }}<span v-if="row.revision_no" class="text-slate-400">/R{{ row.revision_no }}</span></span></template>
                <template #cell:order_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:delivery_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:total="{ row, value }">{{ money(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
