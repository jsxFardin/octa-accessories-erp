<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { baseCurrency, date, money, qty } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    grn: { type: Object, required: true },
    purchaseOrder: { type: Object, default: null },
    lines: { type: Array, default: () => [] },
    lots: { type: Array, default: () => [] },
});

/**
 * BR-55/BR-59 — two units live on this page and only one of them is the factory's.
 *
 * Everything the supplier charged — the line rate, the landed rate, freight, duty, clearing —
 * is in the **purchase order's** currency. The lot costs underneath are what the stock ledger
 * holds, which is always the base currency, because that is the only unit stock can be valued
 * in. Left unlabelled, a USD receipt printed both as taka and the two read as the same number
 * in the same money when they differ by the whole of the exchange rate.
 *
 * A receipt with no order behind it was priced in the factory's currency to begin with.
 */
const orderCurrency = computed(() => props.purchaseOrder?.currency ?? baseCurrency());
</script>

<template>
    <AppLayout>
        <Head :title="grn.number" />

        <template #title>{{ grn.number }}</template>
        <template #subtitle>{{ grn.supplier?.name }} · received {{ date(grn.received_on) }}</template>

        <!-- The receipt sits mid-chain: link up to its PO, forward into the bill it prefills.
             The `?grn_id=` handler in SupplierBillController existed with nothing pointing at it. -->
        <template #actions>
            <Badge :status="grn.status" />
            <Button
                v-if="purchaseOrder && can('purchase_order.view')"
                :href="`/purchase-orders/${purchaseOrder.id}`"
            >
                PO {{ purchaseOrder.number }}
            </Button>
            <Button
                v-if="can('supplier_bill.create')"
                variant="primary"
                :href="`/supplier-bills/create?grn_id=${grn.id}`"
            >
                Create supplier bill
            </Button>
        </template>

        <div class="space-y-4">
            <Card
                title="Landed cost"
                rule="BR-36"
                :subtitle="`Apportioned to lines by value before the weighted average moves · charged in ${orderCurrency}`"
            >
                <dl class="flex flex-wrap gap-8 text-sm">
                    <div><dt class="text-ink-500">Freight</dt><dd class="tnum font-medium">{{ money(grn.freight_amount, orderCurrency) }}</dd></div>
                    <div><dt class="text-ink-500">Duty</dt><dd class="tnum font-medium">{{ money(grn.duty_amount, orderCurrency) }}</dd></div>
                    <div><dt class="text-ink-500">Clearing</dt><dd class="tnum font-medium">{{ money(grn.clearing_amount, orderCurrency) }}</dd></div>
                    <div><dt class="text-ink-500">Invoice</dt><dd class="font-medium">{{ grn.invoice_no ?? '—' }}</dd></div>
                </dl>
            </Card>

            <Card title="Lines" rule="I5 · Gate 2" subtitle="cert_scheme and cert_claim_pct here are the only legitimate origin of a certified claim" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'line_no', label: '#', align: 'center' },
                        { key: 'item_code', label: 'Item' },
                        { key: 'received_qty', label: 'Received', align: 'right' },
                        { key: 'rate', label: 'Rate', align: 'right' },
                        { key: 'landed_rate', label: 'Landed rate', align: 'right' },
                        { key: 'shade_code', label: 'Shade' },
                        { key: 'cert', label: 'Claim' },
                    ]"
                    :rows="lines"
                    row-key="id"
                    empty="No lines."
                    dense
                >
                    <template #cell:item_code="{ row }">
                        <span class="font-medium">{{ row.item_code }}</span>
                        <span class="text-ink-500"> {{ row.item_name }}</span>
                    </template>
                    <template #cell:received_qty="{ row }">{{ qty(row.received_qty) }} {{ row.uom }}</template>
                    <template #cell:rate="{ value }">{{ money(value, orderCurrency) }}</template>
                    <template #cell:landed_rate="{ value }">{{ money(value, orderCurrency) }}</template>
                    <template #cell:cert="{ row }">
                        <Badge v-if="row.cert_scheme" tone="success" :label="`${row.cert_scheme} ${row.cert_claim_pct}%`" />
                        <span v-else class="text-ink-400">—</span>
                    </template>
                </DataTable>
            </Card>

            <Card title="Lots created" rule="I1" :subtitle="`Each line becomes a barcoded lot with a grn_receipt ledger row · valued in ${baseCurrency()}`" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'lot_no', label: 'Lot' },
                        { key: 'balance_qty', label: 'Balance', align: 'right' },
                        { key: 'unit_cost', label: 'Unit cost', align: 'right' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="lots"
                    row-key="id"
                    :row-href="(row) => `/lots/${row.id}`"
                    empty="No lots."
                    dense
                >
                    <template #cell:lot_no="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                    <template #cell:balance_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:unit_cost="{ value }">{{ money(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
