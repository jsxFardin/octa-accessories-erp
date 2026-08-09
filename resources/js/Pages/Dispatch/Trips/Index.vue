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

const props = defineProps({ trips: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number' },
    { key: 'trip_date', label: 'Date' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Trips" />

        <template #title>Trips</template>
        <template #subtitle>The owned fleet: multi-drop routes with POD at each stop</template>

        <template #actions>
            <span />
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['planned','loading','in_transit','completed','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search trip number…" />

            <DataTable
                :columns="columns"
                :rows="trips"
                row-key="id"
                empty="No trips planned."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:trip_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
