<script setup>
import StageStrip from '@/Components/Ui/StageStrip.vue';
import { deadline, useStageFilter } from '@/composables/useStageFilter';
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    rfqs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    /** Every RFQ by status, plus `overdue`, whatever the filters say. */
    counts: { type: Object, default: () => ({}) },
});

const STATUSES = ['draft', 'issued', 'closed', 'cancelled'];

const { stages, select } = useStageFilter('/rfqs', () => props.filters, () => props.counts, {
    statuses: STATUSES,
    alert: { key: 'overdue', label: 'Replies overdue' },
});

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'status', label: 'Status', sort: true },
    { key: 'respond_by', label: 'Replies due', sort: true },
    { key: 'quotations_count', label: 'Quotes in' },
    { key: 'pr_number', label: 'For requisition' },
    { key: 'issued_on', label: 'Issued', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="RFQs" />

        <template #title>Requests for quotation</template>
        <template #subtitle>Issue one RFQ to several suppliers, compare the replies, then raise the PO from the winner.</template>

        <template #actions>
            <Button v-if="can('rfq.create')" variant="primary" href="/rfqs/create">New RFQ</Button>
        </template>

        <StageStrip :stages="stages" label="RFQs by stage" @select="select" />

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: STATUSES.map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'overdue', label: 'Deadline', options: [{ value: '1', label: 'Replies overdue' }] },
                ]"
                placeholder="Search RFQ number…"
            />

            <DataTable :columns="columns" :rows="rfqs" row-key="id" :row-href="(row) => `/rfqs/${row.id}`" empty="No RFQs.">
                <template #cell:number="{ row, value }">
                    <Link :href="`/rfqs/${row.id}`" class="doc-link-quiet">{{ value ?? '(draft)' }}</Link>
                    <span class="block text-xs text-ink-500">{{ row.lines_count }} {{ row.lines_count === 1 ? 'material' : 'materials' }}</span>
                </template>
                <template #cell:issued_on="{ value }">{{ value ? date(value) : '—' }}</template>
                <template #cell:respond_by="{ row, value }"><span :class="deadline(value, row.status === 'issued').tone">{{ deadline(value, row.status === 'issued').text }}</span></template>
                <!-- No reply yet on an RFQ that is out is the thing to chase; said, not left as a bare 0. -->
                <template #cell:quotations_count="{ row, value }">
                    <span v-if="value > 0" class="tnum">{{ value }} {{ value === 1 ? 'quote' : 'quotes' }}</span>
                    <span v-else :class="row.status === 'issued' ? 'text-amber-700' : 'text-ink-400'">{{ row.status === 'issued' ? 'None yet' : '—' }}</span>
                </template>
                <template #cell:pr_number="{ row, value }">
                    <Link v-if="value" :href="`/purchase-requisitions/${row.pr_id}`" class="doc-link-quiet">{{ value }}</Link>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="rfq"
                        title="No RFQs yet"
                        description="Raise an RFQ from an approved requisition, issue it, then record the supplier quotations that come back."
                        :action-label="can('rfq.create') ? 'New RFQ' : null"
                        action-href="/rfqs/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
