<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ lots: Object, filters: Object, warehouses: Array });

const columns = [
    { key: 'lot_no', label: 'Lot' },
    { key: 'item', label: 'Item' },
    { key: 'warehouse', label: 'WH' },
    { key: 'shade_code', label: 'Shade' },
    { key: 'balance_qty', label: 'Balance', align: 'right' },
    { key: 'unit_cost', label: 'Unit cost', align: 'right' },
    { key: 'cert', label: 'Claim' },
    { key: 'received_on', label: 'Received' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Stock lots" />

        <template #title>Stock lots</template>
        <template #subtitle>One barcode, one origin, one certification claim (I5)</template>

        <template #actions>
            <span />
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['quarantine','available','reserved','consumed','blocked','expired','scrapped'].map((s) => ({ value: s, label: titleCase(s) })) }, { key: 'warehouse', label: 'Warehouse', options: warehouses.map((w) => ({ value: w.id, label: w.code })) }, { key: 'scheme', label: 'Scheme', options: ['GRS','FSC','OEKO_TEX','SCOPE'].map((s) => ({ value: s, label: s })) }]" placeholder="Search lot number, barcode, batch or shade…" />

            <DataTable
                :columns="columns"
                :rows="lots"
                row-key="id" :row-href="(row) => `/lots/${row.id}`"
                empty="No lots match these filters."
            >
                <template #cell:lot_no="{ row, value }"><span class="font-mono text-xs font-medium text-slate-900">{{ value }}</span></template>
                <template #cell:item="{ row, value }"><span v-if="row.item"><span class="font-medium">{{ row.item.code }}</span> <span class="text-slate-500">{{ row.item.name }}</span></span></template>
                <template #cell:balance_qty="{ row, value }">{{ qty(value) }}</template>
                <template #cell:unit_cost="{ row, value }">{{ money(value) }}</template>
                <template #cell:cert="{ row, value }"><Badge v-if="row.cert_scheme" tone="success" :label="`${row.cert_scheme} ${row.cert_claim_pct}%`" /><span v-else class="text-slate-400">—</span></template>
                <template #cell:received_on="{ row, value }">{{ date(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
