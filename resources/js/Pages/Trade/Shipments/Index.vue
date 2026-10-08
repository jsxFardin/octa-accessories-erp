<script setup>
import StageStrip from '@/Components/Ui/StageStrip.vue';
import { deadline, useStageFilter } from '@/composables/useStageFilter';
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import ExportDialog from '@/Components/Ui/ExportDialog.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    /** Every shipment by status, plus `overdue`, whatever the filters say. */
    counts: { type: Object, default: () => ({}) },
    shipments: Object,
    filters: Object,
    suppliers: { type: Array, default: () => [] },
    modes: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const { stages, select } = useStageFilter('/import-shipments', () => props.filters, () => props.counts, {
    statuses: props.statuses,
    alert: { key: 'overdue', label: 'Overdue' },
});

const columns = [
    { key: 'number', label: 'Shipment', sort: true },
    { key: 'status', label: 'Status' },
    { key: 'eta', label: 'Arrival', sort: true },
    { key: 'supplier', label: 'Supplier' },
    { key: 'transport_doc_no', label: 'Transport document' },
    { key: 'goods_value', label: 'Goods', align: 'right', sort: true },
    { key: 'cost_total', label: 'Landing costs', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head title="Import shipments" />

        <template #title>Import shipments</template>
        <template #subtitle>What is on the water, what it cost to land, and whether that cost has reached the stock</template>

        <template #actions>
            <ExportDialog v-if="can('import_shipment.export')" resource="import-shipments" />
            <Button v-if="can('import_shipment.create')" variant="primary" href="/import-shipments/create">New shipment</Button>
        </template>

        <StageStrip :stages="stages" label="Shipments by stage" @select="select" />

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: statuses.map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'mode', label: 'Mode', options: modes.map((m) => ({ value: m, label: titleCase(m) })) },
                    { key: 'supplier', label: 'Supplier', options: suppliers.map((s) => ({ value: String(s.id), label: s.name })) },
                    { key: 'overdue', label: 'Deadline', options: [{ value: '1', label: 'Past the day due in' }] },
                ]"
                placeholder="Search number, invoice, bill of lading or bill of entry…"
            />

            <DataTable
                :columns="columns"
                :rows="shipments"
                row-key="id"
                :row-href="(row) => `/import-shipments/${row.id}`"
                empty="No shipments match these filters."
            >
                <template #cell:number="{ row, value }">
                    <Link :href="`/import-shipments/${row.id}`" class="doc-link-quiet">{{ value ?? '(unnumbered)' }}</Link>
                    <span class="block text-xs text-ink-500">By {{ row.mode }}<template v-if="row.invoice_no"> · invoice {{ row.invoice_no }}</template></span>
                </template>
                <!-- Arrived is a fact; before that the date is a deadline. -->
                <template #cell:eta="{ row, value }">
                    <span v-if="row.arrived_on">Arrived {{ date(row.arrived_on) }}</span>
                    <span v-else :class="deadline(value, row.status === 'in_transit').tone">{{ deadline(value, row.status === 'in_transit').text }}</span>
                </template>
                <template #cell:transport_doc_no="{ value }"><span :class="value ? '' : 'text-ink-400'">{{ value || 'Not yet' }}</span></template>
                <template #cell:goods_value="{ row, value }"><span class="tnum">{{ money(value, row.currency) }}</span></template>
                <template #cell:cost_total="{ row, value }">
                    <span class="flex items-center justify-end gap-1.5">
                        {{ money(value) }}
                        <!-- Costs recorded but not yet in the stock: the state where every
                             margin the system reports is wrong in the same direction. -->
                        <Badge
                            v-if="Number(value) > 0 && Number(row.allocated_amount) === 0"
                            tone="warning"
                            label="Not in stock cost"
                        />
                    </span>
                </template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>

                <template #empty>
                    <EmptyState
                        icon="ship"
                        title="No shipments yet"
                        description="A shipment is what a freight bill actually belongs to — not a purchase order, not a goods receipt. Record one and the duty that arrives three weeks later has somewhere to land."
                        :action-label="can('import_shipment.create') ? 'New shipment' : null"
                        action-href="/import-shipments/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
