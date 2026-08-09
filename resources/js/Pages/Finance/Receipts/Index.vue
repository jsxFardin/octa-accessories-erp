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

const props = defineProps({ receipts: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number' },
    { key: 'customer_name', label: 'Customer' },
    { key: 'receipt_date', label: 'Date' },
    { key: 'amount', label: 'Amount', align: 'right' },
    { key: 'mode', label: 'Mode' },
];
</script>

<template>
    <AppLayout>
        <Head title="Receipts" />

        <template #title>Receipts</template>
        <template #subtitle>Allocated against invoices; the remainder is the customer\u2019s advance</template>

        <template #actions>
            <span />
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[]" placeholder="Search receipt or reference number…" />

            <DataTable
                :columns="columns"
                :rows="receipts"
                row-key="id"
                empty="No receipts recorded."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-slate-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:customer_name="{ row, value }">{{ row.customer?.name ?? "—" }}</template>
                <template #cell:receipt_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:amount="{ row, value }">{{ money(value) }}</template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
