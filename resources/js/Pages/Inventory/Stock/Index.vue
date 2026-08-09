<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, qty } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    rows: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    warehouses: { type: Array, default: () => [] },
    reconciliation: { type: Object, required: true },
});

const columns = [
    { key: 'lot_no', label: 'Lot' },
    { key: 'item_code', label: 'Item' },
    { key: 'warehouse', label: 'WH' },
    { key: 'shade_code', label: 'Shade' },
    { key: 'balance_qty', label: 'Balance', align: 'right' },
    { key: 'value', label: 'Value', align: 'right' },
    { key: 'cert', label: 'Claim' },
    { key: 'ageing_bucket', label: 'Age' },
    { key: 'expiry_alert', label: 'Expiry' },
];
</script>

<template>
    <AppLayout>
        <Head title="Stock enquiry" />

        <template #title>Stock enquiry</template>
        <template #subtitle>Balances from the summary table, reconciled against the append-only ledger</template>

        <div class="space-y-4">
            <!--
                The reconciliation is a defect check, not routine maintenance. A difference means a
                posting path bypassed StockPostingService, and it is raised rather than corrected.
            -->
            <div
                class="rounded-lg border px-3 py-2 text-sm"
                :class="reconciliation.mismatched.length
                    ? 'border-rose-200 bg-rose-50 text-rose-900'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-900'"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Badge
                        :tone="reconciliation.mismatched.length ? 'danger' : 'success'"
                        :label="reconciliation.mismatched.length ? 'Mismatch' : 'Reconciled'"
                    />
                    <span class="font-medium">
                        {{ reconciliation.checked }} lot balance(s) checked against
                        the live ledger
                    </span>
                    <span v-if="reconciliation.mismatched.length" class="text-xs">
                        — {{ reconciliation.mismatched.length }} differ from the ledger. This is a bug in a
                        posting path, not a rounding artefact.
                    </span>
                </div>

                <ul v-if="reconciliation.mismatched.length" class="mt-2 space-y-0.5 font-mono text-xs">
                    <li v-for="row in reconciliation.mismatched" :key="row.lot_id">
                        {{ row.lot_no }}: cached {{ qty(row.cached_qty) }} vs ledger {{ qty(row.ledger_qty) }}
                    </li>
                </ul>
            </div>

            <Card :padded="false">
                <FilterBar
                    :filters="filters"
                    :fields="[
                        { key: 'warehouse', label: 'Warehouse', options: warehouses.map((w) => ({ value: w.id, label: w.code })) },
                        { key: 'scheme', label: 'Scheme', options: ['GRS','FSC','OEKO_TEX','SCOPE'].map((s) => ({ value: s, label: s })) },
                        { key: 'nettable', label: 'Nettable', options: [{ value: '1', label: 'MRP-visible only' }] },
                    ]"
                    placeholder="Search item code, name or lot number…"
                />

                <DataTable :columns="columns" :rows="rows" row-key="lot_id" empty="No stock matches these filters." dense>
                    <template #cell:lot_no="{ row }">
                        <Link :href="`/lots/${row.lot_id}`" class="font-mono text-xs font-medium text-brand-700">
                            {{ row.lot_no }}
                        </Link>
                    </template>
                    <template #cell:item_code="{ row }">
                        <span class="font-medium">{{ row.item_code }}</span>
                        <span class="text-slate-500"> {{ row.item_name }}</span>
                    </template>
                    <template #cell:warehouse="{ row }">
                        {{ row.warehouse }}
                        <!-- BR-24: scrap and transit exist, but MRP may not plan against them -->
                        <Badge v-if="!row.is_nettable" tone="neutral" label="non-net" class="ml-1" />
                    </template>
                    <template #cell:balance_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:value="{ value }">{{ money(value) }}</template>
                    <template #cell:cert="{ row }">
                        <Badge v-if="row.cert_scheme" tone="success" :label="`${row.cert_scheme} ${row.cert_claim_pct}%`" />
                        <span v-else class="text-slate-400">—</span>
                    </template>
                    <template #cell:expiry_alert="{ row }">
                        <Badge v-if="row.expiry_alert === 'expired'" tone="danger" label="Expired" />
                        <Badge v-else-if="row.expiry_alert === 'expiring_soon'" tone="warning" :label="date(row.expiry_date)" />
                        <span v-else class="text-slate-400">—</span>
                    </template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
