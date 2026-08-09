<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ purchase_requisitions: Object, filters: Object });

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/purchase-requisitions/${row.id}`) },
        { label: 'Edit', hidden: !can('purchase_requisition.update') || !(row.status === 'draft'), onSelect: () => router.visit(`/purchase-requisitions/${row.id}/edit`) },
    ];
}

const columns = [
    { key: 'number', label: 'Number' },
    { key: 'origin', label: 'Origin' },
    { key: 'requested_on', label: 'Raised' },
    { key: 'required_by', label: 'Required' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Purchase requisitions" />

        <template #title>Purchase requisitions</template>
        <template #subtitle>Shortages raised by an MRP run arrive here (BR-24)</template>

        <template #actions>
            <Button v-if="can('purchase_requisition.create')" variant="primary" href="/purchase-requisitions/create">
                New requisition
            </Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','submitted','approved','converted','rejected','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search requisition number…" />

            <DataTable
                :columns="columns"
                :rows="purchase_requisitions"
                row-key="id" :actions="rowActions" :row-href="(row) => `/purchase-requisitions/${row.id}`"
                empty="No requisitions raised."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:origin="{ row, value }">{{ titleCase(value) }}</template>
                <template #cell:requested_on="{ row, value }">{{ date(value) }}</template>
                <template #cell:required_by="{ row, value }">{{ value ? date(value) : "—" }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
