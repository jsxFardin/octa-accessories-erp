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

const props = defineProps({ packing_lists: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'packed_on', label: 'Packed', sort: true },
    { key: 'total_cartons', label: 'Cartons', align: 'right' },
    { key: 'total_qty', label: 'Pieces', align: 'right' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Packing lists" />

        <template #title>Packing lists</template>
        <template #subtitle>Every carton's contents name their lot (D1)</template>

        <template #actions>
            <Button v-if="can('packing_list.create')" size="sm" variant="primary" href="/packing-lists/create">New packing list</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','packed','dispatched','delivered'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search packing list number…" />

            <DataTable
                :columns="columns"
                :rows="packing_lists"
                row-key="id"
                empty="No packing lists yet."
            >
                <template #cell:number="{ row, value }"><Link :href="`/packing-lists/${row.id}`" class="font-medium text-brand-700">{{ value ?? "(draft)" }}</Link></template>
                <template #cell:packed_on="{ row, value }">{{ date(value) }}</template>
                <template #cell:total_qty="{ row, value }">{{ pcs(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="packing"
                        title="Nothing packed yet"
                        description="Scan-to-pack builds the carton contents that a challan and an invoice are drawn from."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
