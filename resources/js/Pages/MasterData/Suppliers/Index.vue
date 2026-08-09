<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ suppliers: Object, filters: Object });

function remove(row) {
    if (!confirm(`Archive ${row.code ?? row.name}? History is kept; it simply stops being offered.`)) return;

    router.delete(`/suppliers/${row.id}`, { preserveScroll: true });
}

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/suppliers/${row.id}`) },
        { label: 'Edit', hidden: !can('supplier.update'), onSelect: () => router.visit(`/suppliers/${row.id}/edit`) },
        {
            label: 'Archive',
            tone: 'danger',
            hidden: !can('supplier.delete'),
            onSelect: () => remove(row),
        },
    ];
}

const columns = [
    { key: 'code', label: 'Code', sort: true },
    { key: 'name', label: 'Name', sort: true },
    { key: 'country', label: 'Country', sort: true },
    { key: 'lead_time_days', label: 'Lead days', align: 'right' },
    { key: 'rating', label: 'Rating', align: 'right', sort: true },
    { key: 'is_approved', label: 'Approved' },
    { key: 'is_active', label: 'Active' },
];
</script>

<template>
    <AppLayout>
        <Head title="Suppliers" />

        <template #title>Suppliers</template>
        <template #subtitle>Yarn, ribbon, ink and chemicals — lead time is per supplier-item (BR-26)</template>

        <template #actions>
            <Button v-if="can('supplier.create')" variant="primary" href="/suppliers/create">New supplier</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'approved', label: 'Approved', options: [{ value: '1', label: 'Approved' }, { value: '0', label: 'Not approved' }] }]" placeholder="Search code, name or country…" />

            <DataTable
                :columns="columns"
                :rows="suppliers"
                row-key="id" :actions="rowActions" :row-href="(row) => `/suppliers/${row.id}`"
                empty="No suppliers match these filters."
            >
                <template #cell:code="{ row, value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:is_approved="{ row, value }"><Badge :tone="value ? 'success' : 'warning'" :label="value ? 'Approved' : 'Pending'" /></template>
                <template #cell:is_active="{ row, value }"><Badge :tone="value ? 'success' : 'neutral'" :label="value ? 'Active' : 'Inactive'" /></template>
                <template #empty>
                    <EmptyState
                        icon="supplier"
                        title="No suppliers yet"
                        description="Only an approved supplier can be sent a purchase order, and only their lots can carry a certification claim."
                        :action-label="can('supplier.create') ? 'New supplier' : null"
                        action-href="/suppliers/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
