<script setup>
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

const props = defineProps({ purchase_requisitions: Object, filters: Object });

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
    [{ label: 'Approve selected', tone: 'success', icon: 'check', to: 'approved', permission: 'purchase_requisition.approve' }, { label: 'Submit selected', tone: 'primary', icon: 'send', to: 'submitted', permission: 'purchase_requisition.submit' }]
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
    { key: 'origin', label: 'Origin' },
    { key: 'requested_on', label: 'Raised', sort: true },
    { key: 'required_by', label: 'Required', sort: true },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Purchase requisitions" />

        <template #title>Purchase requisitions</template>
        <template #subtitle>Shortages raised by an MRP run arrive here (BR-24)</template>

        <template #actions>
            <Button v-if="can('purchase_requisition.create')" variant="primary" href="/purchase-requisitions/create">
                New requisition
            </Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','submitted','approved','converted','rejected','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search requisition number…" />

            <DataTable
                :columns="columns"
                :rows="purchase_requisitions"
                row-key="id" v-model:selection="selection" selectable :actions="rowActions" :row-href="(row) => `/purchase-requisitions/${row.id}`"
                empty="No requisitions raised."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:origin="{ row, value }">{{ titleCase(value) }}</template>
                <template #cell:requested_on="{ row, value }">{{ date(value) }}</template>
                <template #cell:required_by="{ row, value }">{{ value ? date(value) : "—" }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="requisition"
                        title="No requisitions yet"
                        description="A requisition is what the factory asks for, before anyone agrees to buy it. MRP shortages land here too."
                        :action-label="can('purchase_requisition.create') ? 'New requisition' : null"
                        action-href="/purchase-requisitions/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>

        <BulkBar
            :count="selection.length"
            :actions="bulkActions"
            :busy="bulkBusy"
            @clear="selection = []"
        />
    </AppLayout>
</template>
