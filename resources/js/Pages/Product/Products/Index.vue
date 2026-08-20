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

const props = defineProps({ products: Object, filters: Object, customers: Array, productTypes: Array });

async function remove(row) {
    if (!await confirm({
        title: `Archive ${row.code ?? row.name}?`,
        message: 'History is kept; it simply stops being offered.',
        confirmLabel: 'Archive',
    })) return;

    router.delete(`/products/${row.id}`, { preserveScroll: true });
}

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/products/${row.id}`) },
        { label: 'Edit', hidden: !can('product.update'), onSelect: () => router.visit(`/products/${row.id}/edit`) },
        {
            label: 'Archive',
            tone: 'danger',
            hidden: !can('product.delete'),
            onSelect: () => remove(row),
        },
    ];
}

const columns = [
    { key: 'code', label: 'Code', sort: true },
    { key: 'name', label: 'Name', sort: true },
    { key: 'customer', label: 'Customer' },
    { key: 'product_type', label: 'Type', sort: true },
    { key: 'customer_style_ref', label: 'Style ref' },
    { key: 'spec_version', label: 'Spec', align: 'center' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Products" />

        <template #title>Products</template>
        <template #subtitle>Finished labels the customer orders. Yarn, ink and packing are under Inventory → Materials.</template>

        <template #actions>
            <ImportDialog v-if="can('product.import')" resource="products" label="Products" />
            <ExportDialog v-if="can('product.export')" resource="products" />
            <Button v-if="can('product.create')" variant="primary" href="/products/create">New product</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: c.id, label: c.name })) }, { key: 'type', label: 'Type', options: productTypes.map((t) => ({ value: t.value, label: t.label })) }, { key: 'status', label: 'Status', options: ['development','active','on_hold','discontinued'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search code, name or customer style ref…" />

            <DataTable
                :columns="columns"
                :rows="products"
                row-key="id" :actions="rowActions" :row-href="(row) => `/products/${row.id}`"
                empty="No products match these filters."
            >
                <template #cell:code="{ row, value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:product_type="{ row, value }">{{ titleCase(value) }}</template>
                <template #cell:spec_version="{ row, value }"><Badge v-if="value" tone="success" :label="`v${value}`" /><Badge v-else tone="danger" label="none" /></template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="product"
                        title="No products yet"
                        description="A product belongs to one customer and carries the spec every job card is built from."
                        :action-label="can('product.create') ? 'New product' : null"
                        action-href="/products/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
