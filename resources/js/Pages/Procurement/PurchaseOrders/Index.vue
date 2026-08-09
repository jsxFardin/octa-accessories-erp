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

const props = defineProps({ purchase_orders: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number' },
    { key: 'supplier_name', label: 'Supplier' },
    { key: 'order_date', label: 'Ordered' },
    { key: 'expected_date', label: 'Expected' },
    { key: 'total', label: 'Value', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Purchase orders" />

        <template #title>Purchase orders</template>
        <template #subtitle>Approval routes by value band; the bands are settings, not code</template>

        <template #actions>
            <span />
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','pending_approval','approved','sent','partially_received','received','closed','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search PO number…" />

            <DataTable
                :columns="columns"
                :rows="purchase_orders"
                row-key="id" :row-href="(row) => `/purchase-orders/${row.id}`"
                empty="No purchase orders match these filters."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-slate-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:supplier_name="{ row, value }">{{ row.supplier?.name ?? "—" }}</template>
                <template #cell:order_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:expected_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:total="{ row, value }">{{ money(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
