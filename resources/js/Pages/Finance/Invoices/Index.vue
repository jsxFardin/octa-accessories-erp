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

const props = defineProps({ sales_invoices: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number' },
    { key: 'customer_name', label: 'Customer' },
    { key: 'invoice_date', label: 'Date' },
    { key: 'due_date', label: 'Due' },
    { key: 'total', label: 'Value', align: 'right' },
    { key: 'received_amount', label: 'Received', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Invoices" />

        <template #title>Invoices</template>
        <template #subtitle>An AR subledger — enough for ageing, credit control and export</template>

        <template #actions>
            <span />
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','issued','partially_paid','paid','overdue','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search invoice or Mushak number…" />

            <DataTable
                :columns="columns"
                :rows="sales_invoices"
                row-key="id"
                empty="No invoices issued."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-slate-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:customer_name="{ row, value }">{{ row.customer?.name ?? "—" }}</template>
                <template #cell:invoice_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:due_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:total="{ row, value }">{{ money(value) }}</template>
                <template #cell:received_amount="{ row, value }">{{ money(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
