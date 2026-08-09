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

const props = defineProps({ customers: Object, filters: Object });

function remove(row) {
    if (!confirm(`Archive ${row.code ?? row.name}? History is kept; it simply stops being offered.`)) return;

    router.delete(`/customers/${row.id}`, { preserveScroll: true });
}

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/customers/${row.id}`) },
        { label: 'Edit', hidden: !can('customer.update'), onSelect: () => router.visit(`/customers/${row.id}/edit`) },
        {
            label: 'Archive',
            tone: 'danger',
            hidden: !can('customer.delete'),
            onSelect: () => remove(row),
        },
    ];
}

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'kind', label: 'Kind' },
    { key: 'credit_limit', label: 'Credit limit', align: 'right' },
    { key: 'min_order_value', label: 'Min order', align: 'right' },
    { key: 'under_tolerance_pct', label: 'Under %', align: 'right' },
    { key: 'over_tolerance_pct', label: 'Over %', align: 'right' },
    { key: 'is_active', label: 'Active' },
];
</script>

<template>
    <AppLayout>
        <Head title="Customers" />

        <template #title>Customers</template>
        <template #subtitle>Credit limits, minimum order values and delivery tolerances</template>

        <template #actions>
            <Button v-if="can('customer.create')" variant="primary" href="/customers/create">New customer</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'active', label: 'Status', options: [{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] }]" placeholder="Search code, name or email…" />

            <DataTable
                :columns="columns"
                :rows="customers"
                row-key="id" :actions="rowActions" :row-href="(row) => `/customers/${row.id}`"
                empty="No customers match these filters."
            >
                <template #cell:code="{ row, value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:credit_limit="{ row, value }">{{ money(value) }}</template>
                <template #cell:min_order_value="{ row, value }">{{ money(value) }}</template>
                <template #cell:is_active="{ row, value }"><Badge :tone="value ? 'success' : 'neutral'" :label="value ? 'Active' : 'Inactive'" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
