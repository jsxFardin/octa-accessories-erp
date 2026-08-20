<script setup>
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { pcs, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ boms: Object, filters: Object });

const columns = [
    { key: 'product_code', label: 'Product' },
    { key: 'version_no', label: 'Version', align: 'center', sort: true },
    { key: 'status', label: 'Status', sort: true },
    { key: 'base_qty', label: 'Per', align: 'right' },
    { key: 'created_at', label: 'Created', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="BOMs" />

        <template #title>Bills of material</template>
        <template #subtitle>What each product consumes. Open a row to see lines on the product. New versions are created from there.</template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[{ key: 'status', label: 'Status', options: ['draft', 'active', 'superseded'].map((s) => ({ value: s, label: titleCase(s) })) }]"
                placeholder="Search product code or name…"
            />

            <DataTable
                :columns="columns"
                :rows="boms"
                row-key="id"
                :row-href="(row) => `/products/${row.product_id}#bom`"
                empty="No bills of material yet."
            >
                <template #cell:product_code="{ row, value }">
                    <span class="font-medium text-ink-900">{{ value }}</span>
                    <span class="text-ink-500"> {{ row.product_name }}</span>
                </template>
                <template #cell:version_no="{ value }">v{{ value }}</template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #cell:base_qty="{ value }">{{ pcs(value) }}</template>
                <template #empty>
                    <EmptyState
                        icon="bom"
                        title="No BOMs yet"
                        description="A bill of material lives on the product. Open a product, then add a BOM before releasing a job card."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get('/boms')"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
