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
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    purchase_requisitions: Object,
    filters: Object,
    /** Every requisition by status, plus `overdue`, whatever the filters say. */
    counts: { type: Object, default: () => ({}) },
});

const STATUSES = ['draft', 'submitted', 'approved', 'partially_ordered', 'ordered', 'rejected', 'cancelled'];
/** Still to be bought: the stages where the day it is wanted by is a deadline. */
const WAITING = ['draft', 'submitted', 'approved', 'partially_ordered'];
const ORIGINS = { manual: 'Raised by hand', mrp: 'Material plan (MRP)', reorder_level: 'Reorder level' };

const { stages, select } = useStageFilter('/purchase-requisitions', () => props.filters, () => props.counts, {
    statuses: STATUSES,
    alert: { key: 'overdue', label: 'Overdue' },
    warn: ['submitted'],
});

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/purchase-requisitions/${row.id}`) },
        { label: 'Edit', hidden: !can('purchase_requisition.update') || !(row.status === 'draft'), onSelect: () => router.visit(`/purchase-requisitions/${row.id}/edit`) },
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
    [{ label: 'Approve selected', tone: 'success', icon: 'check', to: 'approved', permission: 'purchase_requisition.approve', confirm: { message: 'An approved requisition can be turned into a purchase order or a request for quotation. Any that cannot be approved are left as they are and named afterwards.' } },
        { label: 'Submit selected', tone: 'primary', icon: 'send', to: 'submitted', permission: 'purchase_requisition.submit', confirm: { message: 'Each requisition goes to an approver and cannot be edited while it waits.', confirmLabel: 'Submit for approval' } }]
        .filter((action) => can(action.permission))
        .map((action) => ({
            ...action,
            onSelect: () => {
                bulkBusy.value = true;

                router.post('/bulk/purchase-requisitions/transition', { ids: selection.value, to: action.to }, {
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
    { key: 'required_by', label: 'Wanted by', sort: true },
    { key: 'origin', label: 'Came from' },
    { key: 'requested_on', label: 'Raised', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Purchase requisitions" />

        <template #title>Purchase requisitions</template>
        <template #subtitle>Shortages found by the material plan (MRP) arrive here</template>

        <template #actions>
            <Button v-if="can('purchase_requisition.create')" variant="primary" href="/purchase-requisitions/create">
                New requisition
            </Button>
        </template>

        <StageStrip :stages="stages" label="Requisitions by stage" @select="select" />

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[
                { key: 'status', label: 'Status', options: STATUSES.map((s) => ({ value: s, label: titleCase(s) })) },
                { key: 'origin', label: 'Origin', options: Object.entries(ORIGINS).map(([value, label]) => ({ value, label })) },
                { key: 'overdue', label: 'Deadline', options: [{ value: '1', label: 'Past the day wanted' }] },
            ]" placeholder="Search number or remarks…" />

            <DataTable
                :columns="columns"
                :rows="purchase_requisitions"
                row-key="id" v-model:selection="selection" selectable :actions="rowActions" :row-href="(row) => `/purchase-requisitions/${row.id}`"
                empty="No requisitions raised."
            >
                <template #cell:number="{ row, value }">
                    <Link :href="`/purchase-requisitions/${row.id}`" class="doc-link-quiet">{{ value ?? '(unnumbered)' }}</Link>
                    <span class="block text-xs text-ink-500">{{ row.lines_count }} {{ row.lines_count === 1 ? 'material' : 'materials' }}</span>
                </template>
                <template #cell:origin="{ value }">{{ ORIGINS[value] ?? titleCase(value) }}</template>
                <template #cell:requested_on="{ value }">{{ date(value) }}</template>
                <template #cell:required_by="{ row, value }"><span :class="deadline(value, WAITING.includes(row.status)).tone">{{ deadline(value, WAITING.includes(row.status)).text }}</span></template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="requisition"
                        title="No requisitions yet"
                        description="A requisition is what the factory asks for, before anyone agrees to buy it. Shortages found by the material plan arrive here too."
                        :action-label="can('purchase_requisition.create') ? 'New requisition' : null"
                        action-href="/purchase-requisitions/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>

        <BulkBar
            :count="selection.length"
            :actions="bulkActions"
            noun="requisition"
            :busy="bulkBusy"
            @clear="selection = []"
        />
    </AppLayout>
</template>
