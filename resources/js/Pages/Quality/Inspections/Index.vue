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

const props = defineProps({ inspections: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'job_card', label: 'Job card' },
    { key: 'stage', label: 'Stage' },
    { key: 'lot_size', label: 'Lot', align: 'right' },
    { key: 'sample_size', label: 'Sample', align: 'right' },
    { key: 'reject_number', label: 'Reject at', align: 'right' },
    { key: 'major_found', label: 'Major', align: 'right' },
    { key: 'dhu', label: 'DHU', align: 'right' },
    { key: 'result', label: 'Result', sort: true },
    { key: 'disposition', label: 'Disposition' },
];
</script>

<template>
    <AppLayout>
        <Head title="QC inspections" />

        <template #title>QC inspections</template>
        <template #subtitle>The verdict is computed from the AQL plan, never typed (BR-30)</template>

        <template #actions>
            <Button v-if="can('qc_inspection.create')" variant="primary" href="/qc-inspections/create">New inspection</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'stage', label: 'Stage', options: ['incoming','in_process','final','pre_dispatch'].map((s) => ({ value: s, label: titleCase(s) })) }, { key: 'result', label: 'Result', options: ['pending','accepted','accepted_with_concession','rejected'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search inspection number…" />

            <DataTable
                :columns="columns"
                :rows="inspections"
                row-key="id" :row-href="(row) => `/qc-inspections/${row.id}`"
                empty="No inspections match these filters."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:stage="{ row, value }">{{ titleCase(value) }}</template>
                <template #cell:lot_size="{ row, value }">{{ pcs(value) }}</template>
                <template #cell:result="{ row, value }"><Badge :status="value" /></template>
                <template #cell:disposition="{ row, value }"><Badge v-if="value" tone="warning" :label="titleCase(value)" /><span v-else class="text-ink-400">—</span></template>
                <template #empty>
                    <EmptyState
                        icon="inspection"
                        title="No inspections yet"
                        description="The AQL plan decides the sample size and the verdict; no lot leaves QC without a disposition."
                        :action-label="can('qc_inspection.create') ? 'New inspection' : null"
                        action-href="/qc-inspections/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
