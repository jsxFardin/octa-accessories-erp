<script setup>
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, money, number, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';
import { useConfirm } from '@/composables/useConfirm';

const props = defineProps({
    bill: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    matchData: { type: Array, default: null },
    payments: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
});

const confirmTransition = useTransitionConfirm('supplier_bill');

async function transition(to) {
    if (!(await confirmTransition(to, props.bill.number))) return;

    router.post(`/supplier-bills/${props.bill.id}/transition`, { to }, { preserveScroll: true });
}

async function transitionWithOverride(to) {
    // Approving *past* a price variance is the riskier click on this page — it was the only
    // transition here that skipped even the ordinary confirm.
    const { confirm } = useConfirm();
    const accepted = await confirm({
        title: `Approve ${props.bill.number} despite the variance?`,
        message: 'The rate variance against the purchase order will be overridden and the bill approved.',
        confirmLabel: 'Override and approve',
        tone: 'danger',
    });

    if (!accepted) return;

    router.post(`/supplier-bills/${props.bill.id}/transition`, { to, override: true }, { preserveScroll: true });
}

const lineColumns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'item_code', label: 'Material' },
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
            <!-- A draft has moved no money and is not yet payable: it can be corrected. -->
            <Button v-if="bill.status === 'draft' && can('supplier_bill.update')" size="sm" :href="`/supplier-bills/${bill.id}/edit`">
                Edit
            </Button>
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
            <!-- The next step for an approved bill: straight to the payment, with this bill chosen. -->
            <Button
                v-if="can('payment.allocate') && ['approved', 'partially_paid'].includes(bill.status) && Number(bill.outstanding) > 0"
                size="sm"
                variant="primary"
                :href="`/payments?bill=${bill.id}`"
            >
                Record payment
            </Button>
            <Button
                v-if="availableTransitions.includes('cancelled')"
                size="sm"
                variant="danger"
                @click="transition('cancelled')"
            >
                Cancel bill
            </Button>
        </template>

        <div class="space-y-4">
            <Card title="Lines" :padded="false">
                <DataTable :columns="lineColumns" :rows="lines" row-key="id" empty="No lines." dense>
                    <template #cell:qty="{ value }">{{ number(value, 2, 2) }}</template>
                    <template #cell:rate="{ value }">{{ money(value, bill.currency) }}</template>
                    <template #cell:amount="{ value }">{{ money(value, bill.currency) }}</template>
                </DataTable>
            </Card>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card title="Totals">
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div><dt class="text-xs text-ink-500">Subtotal</dt><dd class="font-medium tnum">{{ money(bill.subtotal, bill.currency) }}</dd></div>
                        <div><dt class="text-xs text-ink-500">Tax</dt><dd class="font-medium tnum">{{ money(bill.tax_amount, bill.currency) }}</dd></div>
                        <div><dt class="text-xs text-ink-500">Total</dt><dd class="font-medium tnum">{{ money(bill.total, bill.currency) }}</dd></div>
                        <div><dt class="text-xs text-ink-500">Paid</dt><dd class="font-medium tnum text-emerald-700">{{ money(bill.paid_amount, bill.currency) }}</dd></div>
                        <div>
                            <dt class="text-xs text-ink-500">Outstanding</dt>
                            <dd class="font-medium tnum" :class="bill.outstanding > 0 ? 'text-rose-600' : ''">{{ money(bill.outstanding, bill.currency) }}</dd>
                        </div>
                    </dl>
                </Card>

                <Card title="References">
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div v-if="bill.po_number"><dt class="text-xs text-ink-500">Purchase order</dt><dd><a :href="`/purchase-orders/${bill.po_id}`" class="text-brand-700 hover:underline">{{ bill.po_number }}</a></dd></div>
                        <div v-if="bill.grn_number"><dt class="text-xs text-ink-500">Goods receipt</dt><dd><a :href="`/grns/${bill.grn_id}`" class="text-brand-700 hover:underline">{{ bill.grn_number }}</a></dd></div>
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
                                <th class="px-3 py-2">Material</th>
                                <th class="px-3 py-2 text-right">PO qty</th>
                                <th class="px-3 py-2 text-right">Received qty</th>
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
                                <td class="px-3 py-1.5 text-right tnum">{{ row.po_qty == null ? '—' : number(row.po_qty, 2, 2) }}</td>
                                <td class="px-3 py-1.5 text-right tnum">{{ row.grn_qty == null ? '—' : number(row.grn_qty, 2, 2) }}</td>
                                <td class="px-3 py-1.5 text-right tnum">{{ number(row.bill_qty, 2, 2) }}</td>
                                <td class="px-3 py-1.5 text-center">
                                    <span v-if="row.qty_ok === true" class="text-emerald-600">✓</span>
                                    <span v-else-if="row.qty_ok === false" class="text-rose-600">✗</span>
                                    <span v-else class="text-ink-400">—</span>
                                </td>
                                <td class="px-3 py-1.5 text-right tnum">{{ row.po_rate != null ? money(row.po_rate, bill.currency) : '—' }}</td>
                                <td class="px-3 py-1.5 text-right tnum">{{ money(row.bill_rate, bill.currency) }}</td>
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
                        <!-- BR-57 — an allocation is refused across currencies, so a payment against this
                             bill is necessarily in the bill's currency. -->
                        <span class="tnum">{{ money(payment.amount, bill.currency) }}</span>
                    </li>
                    <li v-if="!payments.length" class="px-4 py-6 text-center text-sm text-ink-500">
                        No payments yet.
                    </li>
                </ul>
            </Card>
        </div>
    </AppLayout>
</template>
