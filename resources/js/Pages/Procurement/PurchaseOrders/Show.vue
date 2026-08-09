<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, money, qty } from '@/plugins/formatting';

defineProps({
    purchaseOrder: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    receipts: { type: Array, default: () => [] },
});
</script>

<template>
    <AppLayout>
        <Head :title="purchaseOrder.number ?? 'Purchase order'" />

        <template #title>{{ purchaseOrder.number ?? '(unnumbered)' }}</template>
        <template #subtitle>
            {{ purchaseOrder.supplier?.name }} · ordered {{ date(purchaseOrder.order_date) }}
        </template>

        <template #actions><Badge :status="purchaseOrder.status" /></template>

        <div class="space-y-4">
            <Card
                title="Lines"
                rule="BR-25"
                subtitle="Quantities are rounded to the item's order multiple before the PO is raised"
                :padded="false"
            >
                <DataTable
                    :columns="[
                        { key: 'line_no', label: '#', align: 'center' },
                        { key: 'item_code', label: 'Item' },
                        { key: 'qty', label: 'Ordered', align: 'right' },
                        { key: 'received_qty', label: 'Received', align: 'right' },
                        { key: 'rate', label: 'Rate', align: 'right' },
                        { key: 'amount', label: 'Amount', align: 'right' },
                        { key: 'expected_date', label: 'Expected' },
                        { key: 'cert_claim', label: 'Claim required' },
                    ]"
                    :rows="lines"
                    row-key="id"
                    empty="No lines."
                    dense
                >
                    <template #cell:item_code="{ row }">
                        <span class="font-medium">{{ row.item_code }}</span>
                        <span class="text-slate-500"> {{ row.item_name }}</span>
                    </template>
                    <template #cell:qty="{ row }">{{ qty(row.qty) }} {{ row.uom }}</template>
                    <template #cell:received_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:rate="{ value }">{{ money(value) }}</template>
                    <template #cell:amount="{ value }">{{ money(value) }}</template>
                    <template #cell:expected_date="{ value }">{{ value ? date(value) : '—' }}</template>
                    <template #cell:cert_claim="{ value }">
                        <!-- A line that demands a claim makes the GRN's certification fields mandatory -->
                        <Badge v-if="value" tone="success" :label="value" />
                        <span v-else class="text-slate-400">—</span>
                    </template>
                </DataTable>
            </Card>

            <Card title="Goods receipts" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'number', label: 'GRN' },
                        { key: 'received_on', label: 'Received' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="receipts"
                    row-key="id"
                    :row-href="(row) => `/grns/${row.id}`"
                    empty="Nothing received against this order yet."
                    dense
                >
                    <template #cell:received_on="{ value }">{{ date(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
