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

const props = defineProps({ machines: Object, filters: Object, groups: Array });

function remove(row) {
    if (!confirm(`Retire ${row.code ?? row.name}? History is kept; it simply stops being offered.`)) return;

    router.delete(`/machines/${row.id}`, { preserveScroll: true });
}

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/machines/${row.id}`) },
        { label: 'Edit', hidden: !can('machine.update'), onSelect: () => router.visit(`/machines/${row.id}/edit`) },
        {
            label: 'Retire',
            tone: 'danger',
            hidden: !can('machine.delete'),
            onSelect: () => remove(row),
        },
    ];
}

const columns = [
    { key: 'code', label: 'Code', sort: true },
    { key: 'name', label: 'Name', sort: true },
    { key: 'group', label: 'Group' },
    { key: 'web_width_mm', label: 'Web mm', align: 'right' },
    { key: 'std_rate_per_hour', label: 'Std rate/h', align: 'right' },
    { key: 'hourly_rate', label: 'Cost/h', align: 'right' },
    { key: 'efficiency_pct', label: 'Eff %', align: 'right', sort: true },
    { key: 'status', label: 'Status', sort: true },
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
                row-key="id" :actions="rowActions" :row-href="(row) => `/machines/${row.id}`"
                empty="No machines match these filters."
            >
                <template #cell:code="{ row, value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:std_rate_per_hour="{ row, value }">{{ value ? qty(value, 0) : "—" }}</template>
                <template #cell:hourly_rate="{ row, value }">{{ money(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="machine"
                        title="No machines yet"
                        description="Machine rates and efficiency drive both the cost sheet and the capacity calendar."
                        :action-label="can('machine.create') ? 'New machine' : null"
                        action-href="/machines/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
