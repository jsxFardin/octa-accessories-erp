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

const props = defineProps({ jobCards: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number' },
    { key: 'product', label: 'Product' },
    { key: 'colourway', label: 'Colourway' },
    { key: 'planned_qty', label: 'Planned', align: 'right' },
    { key: 'good_qty', label: 'Good', align: 'right' },
    { key: 'waste_qty', label: 'Waste', align: 'right' },
    { key: 'due_date', label: 'Due' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Job cards" />

        <template #title>Job cards</template>
        <template #subtitle>Bound to a routing and an approved artwork version (Gate 1)</template>

        <template #actions>
            <Button v-if="can('job_card.create')" variant="primary" href="/job-cards/create">New job card</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','planned','material_pending','released','in_production','on_hold','qc_pending','completed','closed','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search job card number or colourway…" />

            <DataTable
                :columns="columns"
                :rows="jobCards"
                row-key="id" :row-href="(row) => `/job-cards/${row.id}`"
                empty="No job cards match these filters."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:product="{ row, value }"><span v-if="row.product"><span class="font-medium">{{ row.product.code }}</span> <span class="text-ink-500">{{ row.product.name }}</span></span></template>
                <template #cell:planned_qty="{ row, value }">{{ pcs(value) }}</template>
                <template #cell:good_qty="{ row, value }">{{ pcs(value) }}</template>
                <template #cell:waste_qty="{ row, value }">{{ pcs(value) }}</template>
                <template #cell:due_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
