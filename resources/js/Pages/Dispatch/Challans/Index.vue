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

const props = defineProps({ delivery_challans: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number' },
    { key: 'customer_name', label: 'Customer' },
    { key: 'challan_date', label: 'Date' },
    { key: 'mode', label: 'Mode' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Delivery challans" />

        <template #title>Delivery challans</template>
        <template #subtitle>Goods leave the factory on a challan; the invoice follows it</template>

        <template #actions>
            <span />
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','issued','in_transit','delivered','returned'].map((s) => ({ value: s, label: titleCase(s) })) }, { key: 'mode', label: 'Mode', options: ['own_fleet','courier','freight_forwarder','customer_pickup'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search challan number…" />

            <DataTable
                :columns="columns"
                :rows="delivery_challans"
                row-key="id"
                empty="No challans issued."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-slate-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:customer_name="{ row, value }">{{ row.customer?.name ?? "—" }}</template>
                <template #cell:challan_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:mode="{ row, value }">{{ titleCase(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
