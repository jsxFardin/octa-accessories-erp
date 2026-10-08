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
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    grns: Object,
    filters: Object,
    suppliers: { type: Array, default: () => [] },
    /** Every goods receipt by status, whatever the filters say. */
    counts: { type: Object, default: () => ({}) },
});

const STATUSES = ['draft', 'pending_qc', 'accepted', 'partially_accepted', 'rejected', 'posted', 'cancelled'];

const { stages, select } = useStageFilter('/grns', () => props.filters, () => props.counts, {
    statuses: STATUSES,
    warn: ['pending_qc'],
});

/** Freight, duty and clearing as one figure: three columns of BDT 0.00 hid the rows that had any. */
const landed = (row) => Number(row.freight_amount ?? 0) + Number(row.duty_amount ?? 0) + Number(row.clearing_amount ?? 0);

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'status', label: 'Status', sort: true },
    { key: 'received_on', label: 'Received', sort: true },
    { key: 'supplier', label: 'Supplier' },
    { key: 'po_number', label: 'Against order' },
    { key: 'invoice_no', label: 'Supplier invoice' },
    { key: 'landed', label: 'Landing costs', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head title="Goods receipts" />

        <template #title>Goods receipts</template>
        <template #subtitle>What has arrived, against which order, and whether it has passed inspection into stock</template>

        <template #actions>
            <Button v-if="can('grn.create')" variant="primary" href="/grns/create">New goods receipt</Button>
        </template>

        <StageStrip :stages="stages" label="Goods receipts by stage" @select="select" />

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[
                { key: 'status', label: 'Status', options: STATUSES.map((s) => ({ value: s, label: titleCase(s) })) },
                { key: 'supplier', label: 'Supplier', options: suppliers.map((s) => ({ value: s.id, label: s.name, code: s.code })) },
            ]" placeholder="Search goods receipt, bill or delivery note number…" />

            <DataTable
                :columns="columns"
                :rows="grns"
                row-key="id" :row-href="(row) => `/grns/${row.id}`"
                empty="No goods receipts match these filters."
            >
                <template #cell:number="{ row, value }">
                    <Link :href="`/grns/${row.id}`" class="doc-link-quiet">{{ value ?? '(unnumbered)' }}</Link>
                </template>
                <template #cell:received_on="{ value }">{{ date(value) }}</template>
                <template #cell:po_number="{ row, value }">
                    <Link v-if="value && can('purchase_order.view_any')" :href="`/purchase-orders/${row.po_id}`" class="doc-link-quiet">{{ value }}</Link>
                    <span v-else :class="value ? '' : 'text-ink-400'">{{ value ?? (row.po_id ? 'Draft order' : 'No order') }}</span>
                </template>
                <template #cell:invoice_no="{ value }"><span :class="value ? '' : 'text-ink-400'">{{ value || '—' }}</span></template>
                <template #cell:landed="{ row }">
                    <span v-if="landed(row) > 0" class="tnum" :title="`Freight ${money(row.freight_amount)}, duty ${money(row.duty_amount)}, clearing ${money(row.clearing_amount)}`">{{ money(landed(row)) }}</span>
                    <span v-else class="text-ink-400">None</span>
                </template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="goods-receipt"
                        title="Nothing received yet"
                        description="A goods receipt is where lots are born and where a certification claim legitimately enters the system."
                        :action-label="can('grn.create') ? 'New goods receipt' : null"
                        action-href="/grns/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
