<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ sales_returns: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'customer', label: 'Customer' },
    { key: 'invoice', label: 'Billed on' },
    { key: 'returned_on', label: 'Returned', sort: true },
    { key: 'lines_count', label: 'Lines', align: 'right' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Customer returns" />

        <template #title>Customer returns</template>
        <template #subtitle>
            Goods a customer has sent back after delivery. The invoice they were billed on is not reopened.
        </template>

        <template #actions>
            <Button v-if="can('sales_return.create')" size="sm" variant="primary" href="/sales-returns/create">
                New return
            </Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[{
                    key: 'status',
                    label: 'Status',
                    options: ['draft', 'approved', 'posted', 'cancelled'].map((s) => ({ value: s, label: titleCase(s) })),
                }]"
                placeholder="Search return number or reason…"
            />

            <DataTable :columns="columns" :rows="sales_returns" row-key="id" empty="No customer returns.">
                <template #cell:number="{ row, value }">
                    <Link :href="`/sales-returns/${row.id}`" class="doc-link-quiet">{{ value ?? '(draft)' }}</Link>
                </template>
                <template #cell:invoice="{ row }">
                    <span v-if="row.invoice" class="inline-flex items-center gap-1.5">
                        <Link :href="`/invoices/${row.invoice.id}`" class="doc-link-quiet">{{ row.invoice.number }}</Link>
                        <!-- The invoice keeps its own status through all of this. -->
                        <Badge :status="row.invoice.status" />
                    </span>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:returned_on="{ value }">{{ date(value) }}</template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
