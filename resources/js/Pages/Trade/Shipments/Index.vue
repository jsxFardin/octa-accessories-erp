<script setup>
import { Head, router } from '@inertiajs/vue3';
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

defineProps({
    shipments: Object,
    filters: Object,
    suppliers: { type: Array, default: () => [] },
    modes: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const columns = [
    { key: 'number', label: 'Shipment', sort: true },
    { key: 'supplier', label: 'Supplier' },
    { key: 'invoice_no', label: 'Invoice' },
    { key: 'transport_doc_no', label: 'BL / AWB' },
    { key: 'mode', label: 'Mode' },
    { key: 'eta', label: 'ETA', sort: true },
    { key: 'goods_value', label: 'Goods', align: 'right', sort: true },
    { key: 'cost_total', label: 'Costs', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Import shipments" />

        <template #title>Import shipments</template>
        <template #subtitle>What is on the water, what it cost to land, and whether that cost has reached the stock</template>

        <template #actions>
            <ExportDialog v-if="can('import_shipment.export')" resource="import-shipments" />
            <Button v-if="can('import_shipment.create')" variant="primary" href="/import-shipments/create">New shipment</Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: statuses.map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'mode', label: 'Mode', options: modes.map((m) => ({ value: m, label: titleCase(m) })) },
                    { key: 'supplier', label: 'Supplier', options: suppliers.map((s) => ({ value: String(s.id), label: s.name })) },
                ]"
                placeholder="Search number, invoice, BL or bill of entry…"
            />

            <DataTable
                :columns="columns"
                :rows="shipments"
                row-key="id"
                :row-href="(row) => `/import-shipments/${row.id}`"
                empty="No shipments match these filters."
            >
                <template #cell:number="{ value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:mode="{ value }">{{ titleCase(value) }}</template>
                <template #cell:eta="{ value }">{{ value ? date(value) : '—' }}</template>
                <template #cell:goods_value="{ value }">{{ money(value) }}</template>
                <template #cell:cost_total="{ row, value }">
                    <span class="flex items-center justify-end gap-1.5">
                        {{ money(value) }}
                        <!-- Costs recorded but not yet in the stock: the state where every
                             margin the system reports is wrong in the same direction. -->
                        <Badge
                            v-if="Number(value) > 0 && Number(row.allocated_amount) === 0"
                            tone="warning"
                            label="unallocated"
                        />
                    </span>
                </template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>

                <template #empty>
                    <EmptyState
                        icon="ship"
                        title="No shipments yet"
                        description="A shipment is what a freight bill actually belongs to — not a purchase order, not a goods receipt. Record one and the duty that arrives three weeks later has somewhere to land."
                        :action-label="can('import_shipment.create') ? 'New shipment' : null"
                        action-href="/import-shipments/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
