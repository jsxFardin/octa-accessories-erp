<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { baseCurrency, date, inBaseCurrency, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import { conversionAction } from '@/plugins/documentActions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';
import RuleHint from '@/Components/Ui/RuleHint.vue';

const props = defineProps({
    quotation: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
    /** The inquiry this answers, when it came from one. */
    inquiry: { type: Object, default: null },
    /** The order(s) it was converted into. */
    orders: { type: Array, default: () => [] },
    /** Q5, as the server answers it: whether a conversion is still available, and why not. */
    conversion: { type: Object, default: () => ({ convertible: false, refusal: null, live_orders: [] }) },
});

const rejectOpen = ref(false);

function duplicate() {
    router.post(`/quotations/${props.quotation.id}/duplicate`);
}
const convertOpen = ref(false);

/** Q5 — what this quotation offers next, decided the same way the server decides it. */
const convertAction = computed(() => conversionAction(props.quotation, props.conversion, can));

const rejectForm = useForm({ to: 'rejected', reject_reason: '' });
const convertForm = useForm({ customer_po_no: '', delivery_date: '' });

const confirmTransition = useTransitionConfirm();

async function transition(to) {
    if (!(await confirmTransition(to, props.quotation.number))) return;

    router.post(`/quotations/${props.quotation.id}/transition`, { to }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="quotation.reference" />

        <template #title>{{ quotation.reference }}</template>
        <template #subtitle>
            {{ quotation.customer?.name }} · {{ date(quotation.quotation_date) }}
            <span v-if="inquiry">
                · from
                <Link :href="`/inquiries/${inquiry.id}`" class="hover:underline">
                    inquiry {{ inquiry.number ?? `#${inquiry.id}` }}</Link>
            </span>
        </template>

        <template #actions>
            <Badge :status="quotation.status" />
            <Button v-if="availableTransitions.includes('sent')" size="sm" variant="primary" @click="transition('sent')">Send</Button>
            <!--
                Q5. A quotation that already became an order offers that order; the convert
                action is not merely hidden here — the POST handler refuses it too. The choice
                itself lives in `documentActions.js` so it can be tested.
            -->
            <Button
                v-if="convertAction.kind === 'convert'"
                size="sm"
                variant="primary"
                @click="convertOpen = true"
            >{{ convertAction.label }}</Button>
            <Button
                v-else-if="convertAction.kind === 'view-order'"
                size="sm"
                variant="primary"
                :href="convertAction.href"
            >{{ convertAction.label }}</Button>
            <Button
                v-else-if="convertAction.kind === 'already-converted'"
                size="sm"
                variant="primary"
                disabled
                :title="convertAction.title ?? ''"
            >{{ convertAction.label }}</Button>
            <Button v-if="availableTransitions.includes('accepted')" size="sm" variant="success" @click="transition('accepted')">Customer accepted</Button>
            <Button v-if="availableTransitions.includes('revised')" size="sm" @click="transition('revised')">Revise</Button>
            <Button v-if="can('quotation.update') && quotation.status === 'draft'" size="sm" :href="`/quotations/${quotation.id}/edit`">Edit</Button>
            <Button v-if="availableTransitions.includes('rejected')" size="sm" variant="danger" @click="rejectOpen = true">Rejected</Button>
            <!-- Repeat business is the norm: same labels, new season, different quantity. -->
            <Button v-if="can('quotation.create')" size="sm" @click="duplicate">Duplicate</Button>
            <!-- Opens in its own tab: printing is a detour, not a navigation. -->
            <Button size="sm" :href="`/quotations/${quotation.id}/print`" external target="_blank">Print</Button>
        </template>

        <div class="space-y-4">
            <div
                v-if="orders.length"
                class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-900"
            >
                Converted to
                <template v-for="(order, index) in orders" :key="order.id"><span v-if="index">, </span><Link
                    :href="`/sales-orders/${order.id}`"
                    class="font-medium underline"
                >{{ order.number ?? `draft order #${order.id}` }}</Link> ({{ titleCase(order.status) }})</template>.
            </div>

            <!--
                BR-22/Q1 — the cost sheet is computed in the factory's currency and the document
                is quoted in the customer's. Hiding the conversion is how an unlabelled 52.33
                came to sit beside an unlabelled 3,630,453.60 and read as corrupted data. This
                is true of a draft as much as of a sent quotation, so it is stated on both.
            -->
            <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-ink-700">
                Quoted in <span class="font-medium">{{ quotation.currency?.code ?? baseCurrency() }}</span><template
                    v-if="quotation.currency && quotation.currency.code !== baseCurrency()"
                >, converted to {{ baseCurrency() }} at
                    <span class="font-medium tnum">{{ Number(quotation.exchange_rate).toFixed(4) }}</span>
                    — {{ money(quotation.total, quotation.currency) }} is
                    {{ inBaseCurrency(quotation.total, quotation.currency, quotation.exchange_rate) }} in the books</template>.

                <!-- Q1: a sent quotation is a snapshot, not a live query -->
                <template v-if="quotation.status !== 'draft'">
                    <br>
                    <span class="font-medium">Snapshotted</span> on send (Q1): item rates, machine
                    rates, overhead percentages and that exchange rate are copies. Master data
                    moving since then has not changed a number on this document.
                </template>
            </div>

            <Card
                v-for="line in lines"
                :key="line.id"
                :title="`Line ${line.line_no} — ${line.product?.code ?? ''} ${line.description}`"
                :subtitle="`${pcs(line.qty)} pcs at ${ratePerM(line.rate_per_m)} /M`"
                :padded="false"
            >
                <template #actions>
                    <span class="text-sm font-semibold tnum text-ink-900">{{ money(line.line_total, quotation.currency) }}</span>
                    <Badge v-if="line.cost_sheet?.is_locked" tone="neutral" label="Locked" />
                </template>

                <div v-if="line.cost_sheet" class="grid gap-0 lg:grid-cols-3">
                    <!-- Every line names the rule that produced it (02-database-schema §3.4) -->
                    <div class="lg:col-span-2">
                        <table class="min-w-full text-xs">
                            <thead class="bg-slate-50 text-ink-500">
                                <tr>
                                    <th class="px-3 py-1.5 text-left">Cost type</th>
                                    <th class="px-3 py-1.5 text-left">Basis</th>
                                    <th class="px-3 py-1.5 text-right">Qty</th>
                                    <th class="px-3 py-1.5 text-right">Rate</th>
                                    <th class="px-3 py-1.5 text-right">Amount</th>
                                    <th class="px-3 py-1.5 text-left">Rule</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="cl in line.cost_lines" :key="cl.sequence_no">
                                    <td class="px-3 py-1.5 font-medium text-ink-800">{{ titleCase(cl.cost_type) }}</td>
                                    <td class="px-3 py-1.5 text-ink-500">{{ cl.basis_uom }}</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ qty(cl.qty) }}</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ Number(cl.rate).toFixed(4) }}</td>
                                    <td class="px-3 py-1.5 text-right tnum font-medium">{{ money(cl.amount, quotation.currency) }}</td>
                                    <td class="px-3 py-1.5">
                                        <span v-if="cl.formula_ref" class="rounded bg-slate-100 px-1 font-mono text-[10px] text-ink-700">
                                            {{ cl.formula_ref }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-slate-200 p-3 text-sm lg:border-t-0 lg:border-l">
                        <dl class="space-y-1.5">
                            <div class="flex justify-between"><dt class="text-ink-500">Gross metres</dt><dd class="tnum">{{ qty(line.cost_sheet.gross_metres) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-ink-500">Total wastage</dt><dd class="tnum">{{ Number(line.cost_sheet.total_wastage_pct).toFixed(2) }}%</dd></div>
                            <div class="flex justify-between"><dt class="text-ink-500">Material</dt><dd class="tnum">{{ money(line.cost_sheet.material_cost, quotation.currency) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-ink-500">Machine + labour + energy</dt><dd class="tnum">{{ money(Number(line.cost_sheet.machine_cost) + Number(line.cost_sheet.labour_cost) + Number(line.cost_sheet.energy_cost), quotation.currency) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-ink-500">Overheads</dt><dd class="tnum">{{ money(line.cost_sheet.overhead_amount, quotation.currency) }}</dd></div>
                            <div class="flex justify-between border-t border-slate-200 pt-1.5"><dt class="font-medium">Total cost</dt><dd class="tnum font-medium">{{ money(line.cost_sheet.total_cost, quotation.currency) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-ink-500">Unit cost</dt><dd class="tnum">{{ Number(line.cost_sheet.unit_cost).toFixed(6) }}</dd></div>
                            <div class="flex justify-between">
                                <dt class="flex items-center gap-1 text-ink-500">Margin <RuleHint rule="BR-20" size="size-3" /></dt>
                                <dd class="tnum">{{ Number(line.cost_sheet.margin_pct).toFixed(2) }}%</dd>
                            </div>
                            <div class="flex justify-between rounded bg-brand-50 px-2 py-1">
                                <dt class="font-semibold text-brand-900">Rate / M</dt>
                                <dd class="tnum font-semibold text-brand-900">{{ ratePerM(line.cost_sheet.rate_per_m) }}</dd>
                            </div>
                        </dl>

                        <p class="mt-2 text-[11px] text-ink-500">
                            Margin is applied <strong>on price</strong> — unit cost × 1000 ÷ (1 − margin),
                            not × (1 + margin).
                        </p>
                    </div>
                </div>

                <p v-else class="px-3 py-6 text-center text-sm text-amber-700">
                    No cost sheet on this line. It cannot be sent (Q1).
                </p>
            </Card>

            <Card title="Document total">
                <dl class="flex flex-wrap gap-8 text-sm">
                    <div><dt class="text-ink-500">Subtotal</dt><dd class="text-lg font-semibold tnum">{{ money(quotation.subtotal, quotation.currency) }}</dd></div>
                    <div><dt class="text-ink-500">Tax</dt><dd class="text-lg font-semibold tnum">{{ money(quotation.tax_amount, quotation.currency) }}</dd></div>
                    <div><dt class="text-ink-500">Total</dt><dd class="text-lg font-semibold tnum text-brand-800">{{ money(quotation.total, quotation.currency) }}</dd></div>
                    <div><dt class="text-ink-500">Valid until</dt><dd class="text-lg font-semibold">{{ date(quotation.valid_until) }}</dd></div>
                </dl>
            </Card>
        </div>

        <Modal v-model:open="rejectOpen" title="Customer rejected this quotation">
            <FormField label="Reason" hint="Feeds win/loss analysis." :error="rejectForm.errors.reject_reason" required>
                <textarea v-model="rejectForm.reject_reason" rows="3" class="form-textarea" />
            </FormField>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="danger"
                    :disabled="!rejectForm.reject_reason"
                    :loading="rejectForm.processing"
                    @click="rejectForm.post(`/quotations/${quotation.id}/transition`, { onSuccess: () => (rejectOpen = false) })"
                >
                    Record rejection
                </Button>
            </template>
        </Modal>

        <Modal v-model:open="convertOpen" title="Convert to a sales order" subtitle="Q3: only an accepted quotation converts.">
            <div class="space-y-3">
                <FormField label="Customer PO number" :error="convertForm.errors.customer_po_no">
                    <TextInput v-model="convertForm.customer_po_no" />
                </FormField>
                <FormField label="Delivery date" :error="convertForm.errors.delivery_date">
                    <DateInput v-model="convertForm.delivery_date" />
                </FormField>
            </div>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="convertForm.processing"
                    @click="convertForm.post(`/quotations/${quotation.id}/convert`)"
                >
                    Create draft order
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
