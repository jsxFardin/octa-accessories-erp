<script setup>
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    bills: Object,
    filters: Object,
});

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'bill_no', label: 'Supplier ref' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'bill_date', label: 'Bill date', sort: true },
    { key: 'due_date', label: 'Due date', sort: true },
    { key: 'total', label: 'Total', align: 'right', sort: true },
    { key: 'outstanding', label: 'Outstanding', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Supplier bills" />

        <template #title>Supplier bills</template>
        <template #subtitle>Three-way matched against PO and GRN before approval</template>

        <template #actions>
            <Button v-if="can('supplier_bill.create')" size="sm" variant="primary" :href="'/supplier-bills/create'">
                New bill
            </Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[{ key: 'status', label: 'Status', options: ['draft','approved','partially_paid','paid','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]"
                placeholder="Search bill number…"
            />

            <DataTable :columns="columns" :rows="bills" row-key="id" :row-href="(row) => `/supplier-bills/${row.id}`" empty="No bills.">
                <template #cell:number="{ value }">{{ value ?? '(draft)' }}</template>
                <template #cell:bill_date="{ value }">{{ date(value) }}</template>
                <template #cell:due_date="{ value }">{{ value ? date(value) : '—' }}</template>
                <template #cell:total="{ value }">{{ money(value) }}</template>
                <template #cell:outstanding="{ value }">
                    <span :class="value > 0 ? 'text-rose-600' : ''">{{ money(value) }}</span>
                </template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="bill"
                        title="No supplier bills"
                        description="Enter supplier invoices here. Approval runs a three-way match against PO and GRN."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
