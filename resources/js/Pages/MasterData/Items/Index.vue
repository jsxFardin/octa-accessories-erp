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

const props = defineProps({ items: Object, filters: Object, categories: Array });

function remove(row) {
    if (!confirm(`Deactivate ${row.code ?? row.name}? History is kept; it simply stops being offered.`)) return;

    router.delete(`/items/${row.id}`, { preserveScroll: true });
}

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/items/${row.id}`) },
        { label: 'Edit', hidden: !can('item.update'), onSelect: () => router.visit(`/items/${row.id}/edit`) },
        {
            label: 'Deactivate',
            tone: 'danger',
            hidden: !can('item.delete'),
            onSelect: () => remove(row),
        },
    ];
}

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'category', label: 'Category' },
    { key: 'base_uom', label: 'UoM' },
    { key: 'avg_rate', label: 'Avg rate', align: 'right' },
    { key: 'reorder_level', label: 'Reorder', align: 'right' },
    { key: 'flags', label: 'Flags' },
    { key: 'is_active', label: 'Active' },
];
</script>

<template>
    <AppLayout>
        <Head title="Items" />

        <template #title>Items</template>
        <template #subtitle>Raw materials, consumables and packing — with the technical fields the formulas read</template>

        <template #actions>
            <Button v-if="can('item.create')" variant="primary" href="/items/create">New item</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'category', label: 'Category', options: categories.map((c) => ({ value: c.id, label: c.name })) }, { key: 'active', label: 'Status', options: [{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] }]" placeholder="Search code, name or description…" />

            <DataTable
                :columns="columns"
                :rows="items"
                row-key="id" :actions="rowActions" :row-href="(row) => `/items/${row.id}`"
                empty="No items match these filters."
            >
                <template #cell:code="{ row, value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:avg_rate="{ row, value }">{{ money(value) }}</template>
                <template #cell:reorder_level="{ row, value }">{{ qty(value) }}</template>
                <template #cell:flags="{ row, value }"><span class="flex gap-1">
                        <Badge v-if="row.is_shade_critical" tone="warning" label="Shade" />
                        <Badge v-if="row.has_expiry" tone="info" label="Expiry" />
                    </span></template>
                <template #cell:is_active="{ row, value }"><Badge :tone="value ? 'success' : 'neutral'" :label="value ? 'Active' : 'Inactive'" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
