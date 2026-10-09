<script setup>
import StageStrip from '@/Components/Ui/StageStrip.vue';
import { deadline, useStageFilter } from '@/composables/useStageFilter';
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import BulkBar from '@/Components/Ui/BulkBar.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import ExportDialog from '@/Components/Ui/ExportDialog.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, number, pcs, pct, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    purchase_orders: Object,
    filters: Object,
    suppliers: Array,
    /** Every order by status, plus `late`, whatever the filters say. */
    counts: { type: Object, default: () => ({}) },
});

const STATUSES = ['draft', 'pending_approval', 'approved', 'sent', 'partially_received', 'received', 'closed', 'cancelled'];
/** Placed and not all in: the stages where the expected date is a deadline. */
const AWAITED = ['approved', 'sent', 'partially_received'];

const { stages, select } = useStageFilter('/purchase-orders', () => props.filters, () => props.counts, {
    statuses: STATUSES,
    alert: { key: 'late', label: 'Late' },
    warn: ['pending_approval'],
});

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/purchase-orders/${row.id}`) },
        { label: 'Edit', hidden: !can('purchase_order.update') || !(row.status === 'draft'), onSelect: () => router.visit(`/purchase-orders/${row.id}/edit`) },
    ];
}


const selection = ref([]);
const bulkBusy = ref(false);

/**
 * Bulk actions run server-side one document at a time, through the same state machine as the
 * single-record path — an order above the approval band is refused inside a bulk run exactly
 * as it would be on its own screen, and the response names it.
 */
const bulkActions = computed(() =>
    [{ label: 'Approve selected', tone: 'success', icon: 'check', to: 'approved', permission: 'purchase_order.approve', confirm: { message: 'Each order is approved under the same value limits as on its own page. Any order you may not approve is left as it is and named afterwards.' } },
        { label: 'Send to suppliers', tone: 'primary', icon: 'send', to: 'sent', permission: 'purchase_order.send', confirm: (count) => ({ title: `Mark ${count} purchase order${count === 1 ? '' : 's'} as sent?`, message: 'Each order is treated as placed with its supplier and can no longer be edited. Nothing is emailed from here.', confirmLabel: 'Mark as sent' }) }]
        .filter((action) => can(action.permission))
        .map((action) => ({
            ...action,
            onSelect: () => {
                bulkBusy.value = true;

                router.post('/bulk/purchase-orders/transition', { ids: selection.value, to: action.to }, {
                    preserveScroll: true,
                    onSuccess: () => (selection.value = []),
                    onFinish: () => (bulkBusy.value = false),
                });
            },
        })),
);

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'status', label: 'Status', sort: true },
    { key: 'expected_date', label: 'Expected', sort: true },
    { key: 'supplier_name', label: 'Supplier' },
    { key: 'received_pct', label: 'Received', align: 'right', width: '9rem' },
    { key: 'total', label: 'Value', align: 'right', sort: true },
    { key: 'order_date', label: 'Ordered', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Purchase orders" />

        <template #title>Purchase orders</template>
        <template #subtitle>What has been ordered, from whom, and what is still to come in</template>

        <template #actions>
            <ExportDialog v-if="can('purchase_order.export')" resource="purchase-orders" />
            <Button v-if="can('purchase_order.create')" variant="primary" href="/purchase-orders/create">New order</Button>
        </template>

        <StageStrip :stages="stages" label="Purchase orders by stage" @select="select" />

        <Card :padded="false">
            <FilterBar :only="['purchase_orders', 'filters']" :filters="filters" :fields="[
                { key: 'status', label: 'Status', options: STATUSES.map((s) => ({ value: s, label: titleCase(s) })) },
                { key: 'supplier', label: 'Supplier', options: (suppliers ?? []).map((s) => ({ value: s.id, label: s.name, code: s.code })) },
                { key: 'late', label: 'Deadline', options: [{ value: '1', label: 'Past expected date' }] },
            ]" placeholder="Search number or supplier…" />

            <DataTable
                :only="['purchase_orders', 'filters']"
                :columns="columns"
                :rows="purchase_orders"
                row-key="id" v-model:selection="selection" selectable :actions="rowActions" :row-href="(row) => `/purchase-orders/${row.id}`"
                empty="No purchase orders match these filters."
            >
                <template #cell:number="{ row, value }">
                    <Link :href="`/purchase-orders/${row.id}`" class="doc-link-quiet">{{ value ?? '(unnumbered)' }}</Link>
                    <span class="block text-xs text-ink-500">{{ row.lines_count }} {{ row.lines_count === 1 ? 'material' : 'materials' }}</span>
                </template>
                <template #cell:supplier_name="{ row }">{{ row.supplier?.name ?? '—' }}</template>
                <template #cell:order_date="{ value }">{{ date(value) }}</template>
                <template #cell:expected_date="{ row, value }"><span :class="deadline(value, AWAITED.includes(row.status), { late: 'late' }).tone">{{ deadline(value, AWAITED.includes(row.status), { late: 'late' }).text }}</span></template>
                <!-- How much of the order is in, as a bar. Nothing is shown before it is placed: 0% of a draft is not news. -->
                <template #cell:received_pct="{ row, value }">
                    <span v-if="['draft', 'pending_approval', 'cancelled'].includes(row.status)" class="text-ink-400">—</span>
                    <span v-else class="inline-flex items-center justify-end gap-2">
                        <span class="h-1.5 w-14 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                            <span class="block h-full rounded-full" :class="Number(value) >= 100 ? 'bg-emerald-500' : 'bg-brand-500'" :style="{ width: `${Math.min(100, Number(value) || 0)}%` }" />
                        </span>
                        <span class="w-9 text-right tnum">{{ pct(value ?? 0, 0) }}</span>
                    </span>
                </template>
                <template #cell:total="{ row, value }"><span class="tnum">{{ money(value, row.currency) }}</span></template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="purchase-order"
                        title="No purchase orders yet"
                        description="An order is drafted, approved by whoever its value needs, then sent. Goods are received against it."
                        :action-label="can('purchase_order.create') ? 'New order' : null"
                        action-href="/purchase-orders/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>

        <BulkBar
            :count="selection.length"
            :actions="bulkActions"
            noun="purchase order"
            :busy="bulkBusy"
            @clear="selection = []"
        />
    </AppLayout>
</template>
