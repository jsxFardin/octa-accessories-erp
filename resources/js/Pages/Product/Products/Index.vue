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

const props = defineProps({ products: Object, filters: Object, customers: Array, productTypes: Array });

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'customer', label: 'Customer' },
    { key: 'product_type', label: 'Type' },
    { key: 'customer_style_ref', label: 'Style ref' },
    { key: 'spec_version', label: 'Spec', align: 'center' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Products" />

        <template #title>Products</template>
        <template #subtitle>One product, one customer (P1) — with its current spec version</template>

        <template #actions>
            <Button v-if="can('product.create')" variant="primary" href="/products/create">New product</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: c.id, label: c.name })) }, { key: 'type', label: 'Type', options: productTypes.map((t) => ({ value: t.value, label: t.label })) }, { key: 'status', label: 'Status', options: ['development','active','on_hold','discontinued'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search code, name or customer style ref…" />

            <DataTable
                :columns="columns"
                :rows="products"
                row-key="id" :row-href="(row) => `/products/${row.id}`"
                empty="No products match these filters."
            >
                <template #cell:code="{ row, value }"><span class="font-medium text-slate-900">{{ value }}</span></template>
                <template #cell:product_type="{ row, value }">{{ titleCase(value) }}</template>
                <template #cell:spec_version="{ row, value }"><Badge v-if="value" tone="success" :label="`v${value}`" /><Badge v-else tone="danger" label="none" /></template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
