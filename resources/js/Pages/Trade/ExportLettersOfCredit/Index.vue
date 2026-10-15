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
import { date, money, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const KIND_LABELS = { sight: 'LC at sight', usance: 'LC usance', tt: 'Bank transfer (TT)', da: 'Documents against acceptance (DA)', dp: 'Documents against payment (DP)' };

const props = defineProps({
    counts: { type: Object, default: () => ({}) },
    letters: Object,
    filters: Object,
    customers: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const { stages, select } = useStageFilter('/export-lcs', () => props.filters, () => props.counts, {
    statuses: props.statuses,
    alert: { key: 'expiring', label: 'Expiring or past last shipment' },
});

/** Stages where the two dates are still deadlines. */
const BEFORE_SHIPMENT = ['advised', 'accepted'];
const LIVE = ['advised', 'accepted', 'shipped', 'presented', 'discrepant'];

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'status', label: 'Status' },
    { key: 'last_shipment_date', label: 'Ship by', sort: true },
    { key: 'expiry_date', label: 'Expires', sort: true },
    { key: 'customer', label: 'Customer' },
    { key: 'amount', label: 'Amount', align: 'right', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Export LCs" />

        <template #title>Export letters of credit</template>
        <template #subtitle>The credits customers pay us through, and the two dates that cost the payment when missed</template>

        <template #actions>
            <Button v-if="can('export_lc.create')" variant="primary" href="/export-lcs/create">New export LC</Button>
        </template>

        <StageStrip :stages="stages" label="Export LCs by stage" @select="select" />

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: statuses.map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'kind', label: 'Kind', options: kinds.map((k) => ({ value: k, label: KIND_LABELS[k] ?? titleCase(k) })) },
                    { key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: String(c.id), label: c.name })) },
                    { key: 'expiring', label: 'Deadline', options: [{ value: '1', label: 'Expired, or ship-by within 14 days' }] },
                ]"
                placeholder="Search our number or the bank's…"
            />

            <DataTable :columns="columns" :rows="letters" row-key="id" :row-href="(row) => `/export-lcs/${row.id}`" empty="No export LCs match these filters.">
                <template #cell:number="{ row, value }">
                    <Link :href="`/export-lcs/${row.id}`" class="doc-link-quiet">{{ value }}</Link>
                    <span class="block text-xs text-ink-600">Bank no. {{ row.lc_no }}</span>
                </template>
                <template #cell:customer="{ row, value }">
                    {{ value ?? '—' }}
                    <span class="block text-xs text-ink-500">{{ KIND_LABELS[row.kind] ?? titleCase(row.kind) }}</span>
                </template>
                <template #cell:amount="{ row, value }"><span class="tnum">{{ money(value, row.currency) }}</span></template>
                <template #cell:last_shipment_date="{ row, value }"><span :class="deadline(value, BEFORE_SHIPMENT.includes(row.status), { soon: 14, late: 'past' }).tone">{{ deadline(value, BEFORE_SHIPMENT.includes(row.status), { soon: 14, late: 'past' }).text }}</span></template>
                <template #cell:expiry_date="{ row, value }"><span :class="deadline(value, LIVE.includes(row.status), { soon: 14, late: 'expired' }).tone">{{ deadline(value, LIVE.includes(row.status), { soon: 14, late: 'expired' }).text }}</span></template>
                <template #cell:status="{ row, value }">
                    <Badge :status="value" />
                    <span v-if="value === 'realised' && row.realised_on" class="block text-xs text-ink-500">{{ date(row.realised_on) }}</span>
                </template>
                <template #empty>
                    <EmptyState
                        icon="award"
                        title="No export LCs yet"
                        description="A customer's bank opens a credit in our favour; recording it here is what lets the orders, the shipment, the documents and the proceeds find each other."
                        :action-label="can('export_lc.create') ? 'New export LC' : null"
                        action-href="/export-lcs/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
