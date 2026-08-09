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

const props = defineProps({ packing_lists: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number' },
    { key: 'packed_on', label: 'Packed' },
    { key: 'total_cartons', label: 'Cartons', align: 'right' },
    { key: 'total_qty', label: 'Pieces', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Packing lists" />

        <template #title>Packing lists</template>
        <template #subtitle>Every carton's contents name their lot (D1)</template>

        <template #actions>
            <span />
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','packed','dispatched','delivered'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search packing list number…" />

            <DataTable
                :columns="columns"
                :rows="packing_lists"
                row-key="id"
                empty="No packing lists yet."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:packed_on="{ row, value }">{{ date(value) }}</template>
                <template #cell:total_qty="{ row, value }">{{ pcs(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
