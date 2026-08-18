<script setup>
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, money, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    bill: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    matchData: { type: Array, default: null },
    payments: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
});

function transition(to) {
    router.post(`/supplier-bills/${props.bill.id}/transition`, { to }, { preserveScroll: true });
}

function transitionWithOverride(to) {
    router.post(`/supplier-bills/${props.bill.id}/transition`, { to, override: true }, { preserveScroll: true });
}

const lineColumns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'item_code', label: 'Item' },
    { key: 'description', label: 'Description' },
    { key: 'qty', label: 'Qty', align: 'right' },
    { key: 'rate', label: 'Rate', align: 'right' },
    { key: 'amount', label: 'Amount', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head :title="bill.number ?? 'Supplier bill'" />

        <template #title>{{ bill.number ?? '(draft bill)' }}</template>
        <template #subtitle>
            {{ bill.supplier?.name }} · ref {{ bill.bill_no }} · {{ date(bill.bill_date) }}
        </template>

        <template #actions>
            <Badge :status="bill.status" />
            <Button
                v-if="availableTransitions.includes('approved')"
                size="sm"
                variant="primary"
                @click="transition('approved')"
            >
                Approve
            </Button>
            <Button
                v-if="availableTransitions.includes('approved') && can('supplier_bill.approve_variance')"
                size="sm"
                variant="warning"
                @click="transitionWithOverride('approved')"
            >
                Approve (override variance)
            </Button>
            <Button
                v-if="availableTransitions.includes('cancelled')"
                size="sm"
                variant="danger"
                @click="transition('cancelled')"
            >
                Cancel
            </Button>
        </template>

        <div class="space-y-4">
            <Card title="Lines" :padded="false">
                <DataTable :columns="lineColumns" :rows="lines" row-key="id" empty="No lines." dense>
                    <template #cell:qty="{ value }">{{ Number(value).toFixed(2) }}</template>
                    <template #cell:rate="{ value }">{{ money(value) }}</template>
                    <template #cell:amount="{ value }">{{ money(value) }}</template>
                </DataTable>
            </Card>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card title="Totals">
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div><dt class="text-xs text-ink-500">Subtotal</dt><dd class="font-medium tnum">{{ money(bill.subtotal) }}</dd></div>
                        <div><dt class="text-xs text-ink-500">Tax</dt><dd class="font-medium tnum">{{ money(bill.tax_amount) }}</dd></div>
                        <div><dt class="text-xs text-ink-500">Total</dt><dd class="font-medium tnum">{{ money(bill.total) }}</dd></div>
                        <div><dt class="text-xs text-ink-500">Paid</dt><dd class="font-medium tnum text-emerald-700">{{ money(bill.paid_amount) }}</dd></div>
                        <div>
                            <dt class="text-xs text-ink-500">Outstanding</dt>
                            <dd class="font-medium tnum" :class="bill.outstanding > 0 ? 'text-rose-600' : ''">{{ money(bill.outstanding) }}</dd>
                        </div>
                    </dl>
                </Card>

                <Card title="References">
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div v-if="bill.po_number"><dt class="text-xs text-ink-500">Purchase order</dt><dd><a :href="`/purchase-orders/${bill.po_id}`" class="text-brand-700 hover:underline">{{ bill.po_number }}</a></dd></div>
                        <div v-if="bill.grn_number"><dt class="text-xs text-ink-500">GRN</dt><dd><a :href="`/grns/${bill.grn_id}`" class="text-brand-700 hover:underline">{{ bill.grn_number }}</a></dd></div>
                        <div v-if="bill.due_date"><dt class="text-xs text-ink-500">Due</dt><dd>{{ date(bill.due_date) }}</dd></div>
                        <div v-if="bill.created_by"><dt class="text-xs text-ink-500">Created by</dt><dd>{{ bill.created_by }}</dd></div>
                    </dl>
                </Card>
            </div>

            <Card v-if="matchData && matchData.length" title="Three-way match" rule="PO ↔ GRN ↔ Bill" :padded="false">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase text-ink-500">
                            <tr>
                                <th class="px-3 py-2">Item</th>
                                <th class="px-3 py-2 text-right">PO qty</th>
                                <th class="px-3 py-2 text-right">GRN qty</th>
                                <th class="px-3 py-2 text-right">Bill qty</th>
                                <th class="px-3 py-2 text-center">Qty OK</th>
                                <th class="px-3 py-2 text-right">PO rate</th>
                                <th class="px-3 py-2 text-right">Bill rate</th>
                                <th class="px-3 py-2 text-right">Variance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in matchData" :key="row.item_id">
                                <td class="px-3 py-1.5">#{{ row.item_id }}</td>
                                <td class="px-3 py-1.5 text-right tnum">{{ row.po_qty?.toFixed(2) ?? '—' }}</td>
                                <td class="px-3 py-1.5 text-right tnum">{{ row.grn_qty?.toFixed(2) ?? '—' }}</td>
                                <td class="px-3 py-1.5 text-right tnum">{{ row.bill_qty.toFixed(2) }}</td>
                                <td class="px-3 py-1.5 text-center">
                                    <span v-if="row.qty_ok === true" class="text-emerald-600">✓</span>
                                    <span v-else-if="row.qty_ok === false" class="text-rose-600">✗</span>
                                    <span v-else class="text-ink-400">—</span>
                                </td>
                                <td class="px-3 py-1.5 text-right tnum">{{ row.po_rate != null ? money(row.po_rate) : '—' }}</td>
                                <td class="px-3 py-1.5 text-right tnum">{{ money(row.bill_rate) }}</td>
                                <td class="px-3 py-1.5 text-right tnum" :class="row.rate_variance_pct > 2 ? 'text-rose-600 font-semibold' : ''">
                                    {{ row.rate_variance_pct != null ? `${row.rate_variance_pct}%` : '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card title="Payments against this bill" :padded="false">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="payment in payments" :key="payment.id" class="flex items-center justify-between px-4 py-2">
                        <span class="font-medium">{{ payment.number }}</span>
                        <span class="text-xs text-ink-500">{{ date(payment.payment_date) }} · {{ payment.method }}</span>
                        <span class="tnum">{{ money(payment.amount) }}</span>
                    </li>
                    <li v-if="!payments.length" class="px-4 py-6 text-center text-sm text-ink-500">
                        No payments yet.
                    </li>
                </ul>
            </Card>
        </div>
    </AppLayout>
</template>
