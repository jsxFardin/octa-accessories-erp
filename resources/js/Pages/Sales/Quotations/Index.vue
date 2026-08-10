<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import ExportDialog from '@/Components/Ui/ExportDialog.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ quotations: Object, filters: Object, customers: Array });

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/quotations/${row.id}`) },
        { label: 'Edit', hidden: !can('quotation.update') || row.status !== 'draft', onSelect: () => router.visit(`/quotations/${row.id}/edit`) },
        {
            label: 'Duplicate',
            hidden: !can('quotation.create'),
            onSelect: () => router.post(`/quotations/${row.id}/duplicate`),
        },
    ];
}

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'customer', label: 'Customer' },
    { key: 'quotation_date', label: 'Date', sort: true },
    { key: 'valid_until', label: 'Valid until', sort: true },
    { key: 'lines_count', label: 'Lines', align: 'center' },
    { key: 'total', label: 'Value', align: 'right', sort: true },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Quotations" />

        <template #title>Quotations</template>
        <template #subtitle>A sent quotation is immutable; its cost sheet is snapshotted and locked (Q1)</template>

        <template #actions>
            <ExportDialog v-if="can('quotation.export')" resource="quotations" />
            <Button v-if="can('quotation.create')" variant="primary" href="/quotations/create">New quotation</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','sent','accepted','rejected','revised','expired','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }, { key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: c.id, label: c.name })) }]" placeholder="Search quotation number…" />

            <DataTable
                :columns="columns"
                :rows="quotations"
                row-key="id" :actions="rowActions" :row-href="(row) => `/quotations/${row.id}`"
                empty="No quotations match these filters."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}<span v-if="row.revision_no" class="text-ink-400">/R{{ row.revision_no }}</span></span></template>
                <template #cell:quotation_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:valid_until="{ row, value }">{{ date(value) }}</template>
                <template #cell:total="{ row, value }">{{ money(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="quote"
                        title="No quotations yet"
                        description="A quotation prices an inquiry from a cost sheet, and snapshots that cost when it is sent."
                        :action-label="can('quotation.create') ? 'New quotation' : null"
                        action-href="/quotations/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
