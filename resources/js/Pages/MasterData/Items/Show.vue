<script setup>
import { Head } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, datetime, money, pcs, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ item: Object, stock: Array, lots: Array });

</script>

<template>
    <AppLayout>
        <Head :title="item.code" />

        <template #title>{{ item.code }} · {{ item.name }}</template>
        <template #subtitle>{{ item.category?.name }} · base UoM {{ item.base_uom?.code }}</template>

        <template #actions>
            <Button v-if="can('item.update')" size="sm" :href="`/items/${item.id}/edit`">Edit</Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card title="Master data" class="lg:col-span-1">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-500">Standard rate</dt><dd class="tnum">{{ money(item.std_rate) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Weighted average</dt><dd class="tnum font-medium">{{ money(item.avg_rate) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Reorder level</dt><dd class="tnum">{{ qty(item.reorder_level) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Min order / multiple</dt><dd class="tnum">{{ qty(item.min_order_qty) }} / {{ qty(item.order_multiple) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Safety days</dt><dd class="tnum">{{ item.safety_days }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Ink lay g/m²</dt><dd class="tnum">{{ item.ink_lay_gsm ?? '—' }}</dd></div>
                    <div class="flex gap-1 pt-1">
                        <Badge v-if="item.is_shade_critical" tone="warning" label="Shade critical" />
                        <Badge v-if="item.has_expiry" tone="info" :label="`Expires after ${item.shelf_life_days}d`" />
                        <Badge v-if="item.is_lot_tracked" tone="neutral" label="Lot tracked" />
                    </div>
                </dl>
            </Card>

            <Card class="lg:col-span-2" title="Stock by warehouse" rule="BR-24" subtitle="Non-nettable warehouses hold stock that MRP may not plan against">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="row in stock" :key="row.warehouse_code" class="flex items-center justify-between py-2">
                        <span>
                            <span class="font-medium">{{ row.warehouse_code }}</span>
                            <span class="text-ink-500"> {{ row.warehouse_name }}</span>
                            <Badge v-if="!row.is_nettable" tone="neutral" label="non-net" class="ml-1" />
                        </span>
                        <span class="tnum font-medium">{{ qty(row.balance_qty) }}</span>
                    </li>
                    <li v-if="stock.length === 0" class="py-6 text-center text-ink-500">No stock on hand.</li>
                </ul>
            </Card>

            <Card class="lg:col-span-3" title="Open lots" rule="BR-37 · I5" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'lot_no', label: 'Lot' },
                        { key: 'shade_code', label: 'Shade' },
                        { key: 'balance_qty', label: 'Balance', align: 'right' },
                        { key: 'received_on', label: 'Received' },
                        { key: 'expiry_date', label: 'Expiry' },
                        { key: 'cert', label: 'Claim' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="lots"
                    row-key="id"
                    :row-href="(row) => `/lots/${row.id}`"
                    empty="No open lots."
                    dense
                >
                    <template #cell:lot_no="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                    <template #cell:balance_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:received_on="{ value }">{{ date(value) }}</template>
                    <template #cell:expiry_date="{ value }">{{ value ? date(value) : '—' }}</template>
                    <template #cell:cert="{ row }">
                        <Badge v-if="row.cert_scheme" tone="success" :label="`${row.cert_scheme} ${row.cert_claim_pct}%`" />
                        <span v-else class="text-ink-400">—</span>
                    </template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
