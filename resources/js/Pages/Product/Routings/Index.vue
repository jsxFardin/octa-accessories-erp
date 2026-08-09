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

const props = defineProps({ routings: Object, filters: Object });

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'product_type', label: 'Product type' },
    { key: 'operations_count', label: 'Operations', align: 'center' },
    { key: 'wastage', label: 'Total wastage', align: 'right' },
    { key: 'max_lot_size', label: 'Max lot', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head title="Routings" />

        <template #title>Routings</template>
        <template #subtitle>One default routing per product type, carrying the BR-8 wastage defaults</template>

        <template #actions>
            <span />
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'product_type', label: 'Product type', options: ['woven','flexo','screen','heat_transfer','offset_tag','thermal'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search routing code or name…" />

            <DataTable
                :columns="columns"
                :rows="routings"
                row-key="id"
                empty="No routings defined."
            >
                <template #cell:code="{ row, value }"><span class="font-medium text-slate-900">{{ value }}</span></template>
                <template #cell:product_type="{ row, value }">{{ titleCase(value) }}</template>
                <template #cell:operations_count="{ row, value }">{{ row.operations?.length ?? 0 }}</template>
                <template #cell:wastage="{ row, value }"><span class="tnum">{{ (row.operations ?? []).filter((o) => o.consumes_web).reduce((sum, o) => sum + Number(o.wastage_pct), 0).toFixed(2) }}%</span></template>
                <template #cell:max_lot_size="{ row, value }">{{ value ? pcs(value) : "—" }}</template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
