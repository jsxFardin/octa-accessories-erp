<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import ExportDialog from '@/Components/Ui/ExportDialog.vue';
import ImportDialog from '@/Components/Ui/ImportDialog.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { useConfirm } from '@/composables/useConfirm';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const { confirm } = useConfirm();

const props = defineProps({ items: Object, filters: Object, categories: Array, statuses: Array, families: Array, itemTypes: Array });

async function remove(row) {
    if (!await confirm({
        title: `Deactivate ${row.code ?? row.name}?`,
        message: 'History is kept; it simply stops being offered.',
        confirmLabel: 'Deactivate',
    })) return;

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
    { key: 'code', label: 'Code', sort: true },
    { key: 'name', label: 'Name', sort: true },
    { key: 'category', label: 'Category' },
    { key: 'base_uom', label: 'Unit' },
    { key: 'avg_rate', label: 'Avg rate', align: 'right', sort: true },
    { key: 'reorder_level', label: 'Reorder', align: 'right', sort: true },
    { key: 'flags', label: 'Flags' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Materials" />

        <template #title>Materials</template>
        <template #subtitle>Yarn, ink, packing and other stock you buy — not the labels you sell.</template>

        <template #actions>
            <ImportDialog v-if="can('item.import')" resource="items" label="Materials" />
            <ExportDialog v-if="can('item.export')" resource="items" />
            <Button v-if="can('item.create')" variant="primary" href="/items/create">New material</Button>
        </template>

        <Card :padded="false">
            <FilterBar :only="['items', 'filters']" :filters="filters" :fields="[{ key: 'category', label: 'Category', options: categories.map((c) => ({ value: c.id, label: c.name, code: c.code })) }, { key: 'status', label: 'Status', options: statuses.map((s) => ({ value: s.value, label: s.label })) }, { key: 'type', label: 'Type', options: itemTypes.map((t) => ({ value: t.value, label: t.label })) }, { key: 'family', label: 'Family', options: families.map((f) => ({ value: f.value, label: f.label, code: f.code })) }]" placeholder="Search code, name or description…" />

            <DataTable
                :only="['items', 'filters']"
                :columns="columns"
                :rows="items"
                row-key="id" :actions="rowActions" :row-href="(row) => `/items/${row.id}`"
                empty="No items match these filters."
            >
                <template #cell:code="{ row, value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <!-- A made item is not bought: no average purchase rate and no reorder level to show. -->
                <template #cell:avg_rate="{ row, value }"><span v-if="row.make_or_buy === 'make'" class="text-ink-400">—</span><template v-else>{{ money(value) }}</template></template>
                <template #cell:reorder_level="{ row, value }"><span v-if="row.make_or_buy === 'make'" class="text-ink-400">—</span><template v-else>{{ qty(value) }}</template></template>
                <template #cell:flags="{ row, value }"><span class="flex gap-1">
                        <Badge v-if="row.is_shade_critical" tone="warning" label="Shade" />
                        <Badge v-if="row.has_expiry" tone="info" label="Expiry" />
                    </span></template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="item"
                        title="No materials yet"
                        description="Materials are what the store holds and what a bill of materials uses: yarn, ink, ribbon, cartons."
                        :action-label="can('item.create') ? 'New item' : null"
                        action-href="/items/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
