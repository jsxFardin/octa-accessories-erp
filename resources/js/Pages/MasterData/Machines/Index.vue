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

const props = defineProps({ machines: Object, filters: Object, groups: Array });

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'group', label: 'Group' },
    { key: 'web_width_mm', label: 'Web mm', align: 'right' },
    { key: 'std_rate_per_hour', label: 'Std rate/h', align: 'right' },
    { key: 'hourly_rate', label: 'Cost/h', align: 'right' },
    { key: 'efficiency_pct', label: 'Eff %', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Machines" />

        <template #title>Machines</template>
        <template #subtitle>Rates, kW and efficiency live here, not in a config file (BR-16, BR-18, BR-27)</template>

        <template #actions>
            <Button v-if="can('machine.create')" variant="primary" href="/machines/create">New machine</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'group', label: 'Group', options: groups.map((g) => ({ value: g.id, label: g.name })) }, { key: 'status', label: 'Status', options: ['available','running','maintenance','breakdown','retired'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search code, name, make or model…" />

            <DataTable
                :columns="columns"
                :rows="machines"
                row-key="id" :row-href="(row) => `/machines/${row.id}`"
                empty="No machines match these filters."
            >
                <template #cell:code="{ row, value }"><span class="font-medium text-slate-900">{{ value }}</span></template>
                <template #cell:std_rate_per_hour="{ row, value }">{{ value ? qty(value, 0) : "—" }}</template>
                <template #cell:hourly_rate="{ row, value }">{{ money(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
