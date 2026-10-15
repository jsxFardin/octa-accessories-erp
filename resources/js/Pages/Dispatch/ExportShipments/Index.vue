<script setup>
import StageStrip from '@/Components/Ui/StageStrip.vue';
import { deadline, useStageFilter } from '@/composables/useStageFilter';
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    counts: { type: Object, default: () => ({}) },
    shipments: Object,
    filters: Object,
    customers: { type: Array, default: () => [] },
    modes: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    presentationDays: { type: Number, default: 21 },
});

const { stages, select } = useStageFilter('/export-shipments', () => props.filters, () => props.counts, {
    statuses: props.statuses,
    alert: { key: 'unpresented', label: 'Documents overdue at the bank' },
});

const columns = [
    { key: 'number', label: 'Shipment', sort: true },
    { key: 'status', label: 'Status' },
    { key: 'customer', label: 'Customer' },
    { key: 'etd', label: 'Sails / flies', sort: true },
    { key: 'eta', label: 'Arrives', sort: true },
    { key: 'transport_doc_no', label: 'BL / AWB' },
    { key: 'goods_value', label: 'Goods', align: 'right', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Export shipments" />

        <template #title>Export shipments</template>
        <template #subtitle>What has left the country, under which credit, and whether its documents have reached the bank</template>

        <template #actions>
            <Button v-if="can('export_shipment.create')" variant="primary" href="/export-shipments/create">New shipment</Button>
        </template>

        <StageStrip :stages="stages" label="Export shipments by stage" @select="select" />

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: statuses.map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'mode', label: 'Mode', options: modes.map((m) => ({ value: m, label: titleCase(m) })) },
                    { key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: String(c.id), label: c.name })) },
                    { key: 'unpresented', label: 'Documents', options: [{ value: '1', label: `Shipped over ${presentationDays} days, not presented` }] },
                ]"
                placeholder="Search number, BL/AWB, container or EXP…"
            />

            <DataTable :columns="columns" :rows="shipments" row-key="id" :row-href="(row) => `/export-shipments/${row.id}`" empty="No export shipments match these filters.">
                <template #cell:number="{ row, value }">
                    <Link :href="`/export-shipments/${row.id}`" class="doc-link-quiet">{{ value ?? '(draft)' }}</Link>
                    <span class="block text-xs text-ink-500">{{ titleCase(row.mode) }} · {{ pcs(row.challans_count) }} {{ row.challans_count === 1 ? 'delivery note' : 'delivery notes' }}<template v-if="row.lc"> · LC {{ row.lc }}</template></span>
                </template>
                <template #cell:status="{ row, value }">
                    <Badge :status="value" />
                    <span v-if="value === 'shipped' && row.lc && row.days_since_shipped !== null" class="block text-xs" :class="row.days_since_shipped > presentationDays ? 'text-rose-700' : 'text-ink-500'">{{ row.days_since_shipped }} days since shipping, not presented</span>
                </template>
                <template #cell:etd="{ value }">{{ value ? date(value) : '—' }}</template>
                <template #cell:eta="{ row, value }"><span :class="deadline(value, ['booked', 'shipped', 'presented'].includes(row.status), { soon: 3, late: 'overdue' }).tone">{{ deadline(value, ['booked', 'shipped', 'presented'].includes(row.status), { soon: 3, late: 'overdue' }).text }}</span></template>
                <template #cell:transport_doc_no="{ value, row }"><span class="font-mono text-xs">{{ value ?? '—' }}</span><span v-if="row.container_no" class="block text-xs text-ink-500">{{ row.container_no }}</span></template>
                <template #cell:goods_value="{ row, value }"><span class="tnum">{{ money(value, row.currency) }}</span></template>
                <template #empty>
                    <EmptyState
                        icon="ship"
                        title="No export shipments yet"
                        description="An export shipment carries the transport facts once for the delivery notes on it, and the documents the bank and customs need before it is complete."
                        :action-label="can('export_shipment.create') ? 'New shipment' : null"
                        action-href="/export-shipments/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
