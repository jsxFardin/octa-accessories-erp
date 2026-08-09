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

const props = defineProps({ grns: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'supplier', label: 'Supplier' },
    { key: 'received_on', label: 'Received', sort: true },
    { key: 'invoice_no', label: 'Invoice' },
    { key: 'freight_amount', label: 'Freight', align: 'right' },
    { key: 'duty_amount', label: 'Duty', align: 'right' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Goods receipts" />

        <template #title>Goods receipts</template>
        <template #subtitle>Where certification enters the system — the only legitimate origin of a claim</template>

        <template #actions>
            <Button v-if="can('grn.create')" variant="primary" href="/grns/create">New GRN</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','pending_qc','accepted','partially_accepted','rejected','posted','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search GRN, invoice or challan number…" />

            <DataTable
                :columns="columns"
                :rows="grns"
                row-key="id" :row-href="(row) => `/grns/${row.id}`"
                empty="No goods receipts match these filters."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:received_on="{ row, value }">{{ date(value) }}</template>
                <template #cell:freight_amount="{ row, value }">{{ money(value) }}</template>
                <template #cell:duty_amount="{ row, value }">{{ money(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="goods-receipt"
                        title="Nothing received yet"
                        description="A goods receipt is where lots are born and where a certification claim legitimately enters the system."
                        :action-label="can('grn.create') ? 'New GRN' : null"
                        action-href="/grns/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
