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

/** How the stored kinds are said; the key alone gave "Tt", "Da" and "Dp". */
const KIND_LABELS = { sight: 'LC at sight', usance: 'LC usance', back_to_back: 'Back-to-back LC', tt: 'Bank transfer (TT)', da: 'Documents against acceptance (DA)', dp: 'Documents against payment (DP)' };

const props = defineProps({
    /** Every credit by status, plus `expiring`, whatever the filters say. */
    counts: { type: Object, default: () => ({}) },
    letters: Object,
    filters: Object,
    suppliers: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const { stages, select } = useStageFilter('/letters-of-credit', () => props.filters, () => props.counts, {
    statuses: props.statuses,
    alert: { key: 'expiring', label: 'Expiring or expired' },
});

/** Live credits: the stages where the two dates are deadlines. */
const LIVE = ['applied', 'opened', 'shipped'];

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'status', label: 'Status' },
    { key: 'expiry_date', label: 'Expires', sort: true },
    { key: 'last_shipment_date', label: 'Ship by' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'amount', label: 'Amount', align: 'right', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Letters of credit" />

        <template #title>Letters of credit</template>
        <template #subtitle>The credits raw material is bought against, and the two dates that cost money when missed</template>

        <template #actions>
            <ExportDialog v-if="can('letter_of_credit.export')" resource="letters-of-credit" />
            <Button v-if="can('letter_of_credit.create')" variant="primary" href="/letters-of-credit/create">New credit</Button>
        </template>

        <StageStrip :stages="stages" label="Letters of credit by stage" @select="select" />

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: statuses.map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'kind', label: 'Kind', options: kinds.map((k) => ({ value: k, label: KIND_LABELS[k] ?? titleCase(k) })) },
                    { key: 'supplier', label: 'Supplier', options: suppliers.map((s) => ({ value: String(s.id), label: s.name })) },
                    { key: 'expiring', label: 'Deadline', options: [{ value: '1', label: 'Expired or within 14 days' }] },
                ]"
                placeholder="Search our number or the bank's…"
            />

            <DataTable
                :columns="columns"
                :rows="letters"
                row-key="id"
                :row-href="(row) => `/letters-of-credit/${row.id}`"
                empty="No credits match these filters."
            >
                <!-- Ours, with the bank's under it: one credit, two numbers, and the bank's is the one on the paperwork. -->
                <template #cell:number="{ row, value }">
                    <Link :href="`/letters-of-credit/${row.id}`" class="doc-link-quiet">{{ value ?? '(unnumbered)' }}</Link>
                    <span class="block text-xs" :class="row.lc_no ? 'text-ink-600' : 'text-ink-500'">{{ row.lc_no ? `Bank no. ${row.lc_no}` : 'Not yet opened at the bank' }}</span>
                </template>
                <!-- How it is paid sits under who is paid: a column of its own pushed the amount off the screen. -->
                <template #cell:supplier="{ row, value }">
                    {{ value ?? '—' }}
                    <span class="block text-xs text-ink-500">{{ KIND_LABELS[row.kind] ?? titleCase(row.kind) }}</span>
                </template>
                <template #cell:amount="{ row, value }"><span class="tnum">{{ money(value, row.currency) }}</span></template>
                <template #cell:last_shipment_date="{ row, value }"><span :class="deadline(value, LIVE.includes(row.status) && row.status !== 'shipped', { soon: 14, late: 'past' }).tone">{{ deadline(value, LIVE.includes(row.status) && row.status !== 'shipped', { soon: 14, late: 'past' }).text }}</span></template>
                <template #cell:expiry_date="{ row, value }"><span :class="deadline(value, LIVE.includes(row.status), { soon: 14, late: 'expired' }).tone">{{ deadline(value, LIVE.includes(row.status), { soon: 14, late: 'expired' }).text }}</span></template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>

                <template #empty>
                    <EmptyState
                        icon="ship"
                        title="No letters of credit yet"
                        description="Yarn, ribbon and ink are imported, and nearly every one of those orders is paid through a credit. Recording it here is what lets a shipment, its duty and its true cost find each other later."
                        :action-label="can('letter_of_credit.create') ? 'New credit' : null"
                        action-href="/letters-of-credit/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
