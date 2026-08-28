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

const props = defineProps({ orders: Object, filters: Object, customers: Array });

/**
 * Built per row so the menu never offers what this user may not do, or the record will not
 * allow.
 *
 * F-08 — the dashboard queue "Confirmed orders with no job card" lands here, and the menu
 * offered Open and Edit: the one thing the queue exists for was not on it, so clearing the
 * queue meant opening the order, finding the line, and navigating to planning by hand. The
 * action prefills from the order server-side (`JobCardController::create()`), which is also
 * where the permission is enforced — this only decides whether to show it.
 */
function rowActions(row) {
    return [
        {
            label: 'Create job card',
            hidden: !can('job_card.create') || !row.awaits_job_card,
            onSelect: () => router.visit(`/job-cards/create?sales_order=${row.id}`),
        },
        { label: 'Open', onSelect: () => router.visit(`/sales-orders/${row.id}`) },
        { label: 'Edit', hidden: !can('sales_order.update') || ['closed', 'cancelled'].includes(row.status), onSelect: () => router.visit(`/sales-orders/${row.id}/edit`) },
    ];
}

// Status and Due sit beside the number: they are what the eye scans for, and on a
// narrow screen the rightmost columns are the first to fall off the edge.
const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'status', label: 'Status', sort: true },
    { key: 'delivery_date', label: 'Due', sort: true },
    { key: 'customer', label: 'Customer' },
    { key: 'customer_po_no', label: 'Customer PO' },
    { key: 'order_date', label: 'Ordered', sort: true },
    { key: 'lines_count', label: 'Lines', align: 'center' },
    { key: 'total', label: 'Value', align: 'right', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Sales orders" />

        <template #title>Sales orders</template>
        <template #subtitle>Confirmed orders need a current spec and an approved artwork on every line</template>

        <template #actions>
            <ExportDialog v-if="can('sales_order.export')" resource="sales-orders" />
            <Button v-if="can('sales_order.create')" variant="primary" href="/sales-orders/create">New order</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','credit_hold','confirmed','in_production','partially_delivered','delivered','closed','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }, { key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: c.id, label: c.name })) }]" placeholder="Search order number or customer PO…" />

            <DataTable
                :columns="columns"
                :rows="orders"
                row-key="id" :actions="rowActions" :row-href="(row) => `/sales-orders/${row.id}`"
                empty="No orders match these filters."
            >
                <template #cell:number="{ row, value }"><span class="doc-link-quiet">{{ value ?? "(unnumbered)" }}<span v-if="row.revision_no" class="text-ink-400">/R{{ row.revision_no }}</span></span></template>
                <template #cell:order_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:delivery_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:total="{ row, value }">{{ money(value, row.currency) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="order"
                        title="No sales orders yet"
                        description="An order cannot be confirmed without a current spec and an approved artwork on every line."
                        :action-label="can('sales_order.create') ? 'New order' : null"
                        action-href="/sales-orders/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
