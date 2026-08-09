<script setup>
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

const props = defineProps({ purchase_orders: Object, filters: Object, suppliers: Array });

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/purchase-orders/${row.id}`) },
        { label: 'Edit', hidden: !can('purchase_order.update') || !(row.status === 'draft'), onSelect: () => router.visit(`/purchase-orders/${row.id}/edit`) },
    ];
}

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'supplier_name', label: 'Supplier' },
    { key: 'order_date', label: 'Ordered', sort: true },
    { key: 'expected_date', label: 'Expected', sort: true },
    { key: 'total', label: 'Value', align: 'right', sort: true },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Purchase orders" />

        <template #title>Purchase orders</template>
        <template #subtitle>Approval routes by value band; the bands are settings, not code</template>

        <template #actions>
            <Button v-if="can('purchase_order.create')" variant="primary" href="/purchase-orders/create">New order</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','pending_approval','approved','sent','partially_received','received','closed','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search PO number…" />

            <DataTable
                :columns="columns"
                :rows="purchase_orders"
                row-key="id" :actions="rowActions" :row-href="(row) => `/purchase-orders/${row.id}`"
                empty="No purchase orders match these filters."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:supplier_name="{ row, value }">{{ row.supplier?.name ?? "—" }}</template>
                <template #cell:order_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:expected_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:total="{ row, value }">{{ money(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="purchase-order"
                        title="No purchase orders yet"
                        description="Approval routes by value band; the band is a setting, not code."
                        :action-label="can('purchase_order.create') ? 'New order' : null"
                        action-href="/purchase-orders/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
