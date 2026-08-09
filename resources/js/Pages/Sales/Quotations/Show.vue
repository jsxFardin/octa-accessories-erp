<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    quotation: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
});

const rejectOpen = ref(false);
const convertOpen = ref(false);

const rejectForm = useForm({ to: 'rejected', reject_reason: '' });
const convertForm = useForm({ customer_po_no: '', delivery_date: '' });

function transition(to) {
    router.post(`/quotations/${props.quotation.id}/transition`, { to }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="quotation.reference" />

        <template #title>{{ quotation.reference }}</template>
        <template #subtitle>{{ quotation.customer?.name }} · {{ date(quotation.quotation_date) }}</template>

        <template #actions>
            <Button v-if="can('quotation.update') && quotation.status === 'draft'" size="sm" :href="`/quotations/${quotation.id}/edit`">Edit</Button>
            <Badge :status="quotation.status" />
            <Button v-if="availableTransitions.includes('sent')" size="sm" variant="primary" @click="transition('sent')">Send</Button>
            <Button v-if="availableTransitions.includes('accepted')" size="sm" variant="success" @click="transition('accepted')">Customer accepted</Button>
            <Button v-if="availableTransitions.includes('rejected')" size="sm" variant="danger" @click="rejectOpen = true">Rejected</Button>
            <Button v-if="availableTransitions.includes('revised')" size="sm" @click="transition('revised')">Revise</Button>
            <Button v-if="quotation.status === 'accepted'" size="sm" variant="primary" @click="convertOpen = true">Convert to order</Button>
        </template>

        <div class="space-y-4">
            <!-- Q1: a sent quotation is a snapshot, not a live query -->
            <div
                v-if="quotation.status !== 'draft'"
                class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-ink-700"
            >
                <span class="font-medium">Snapshotted</span> on send (Q1): item rates, machine rates,
                overhead percentages and the exchange rate ({{ Number(quotation.exchange_rate).toFixed(4) }})
                are copies. Master data moving since then has not changed a number on this document.
            </div>

            <Card
                v-for="line in lines"
                :key="line.id"
                :title="`Line ${line.line_no} — ${line.product?.code ?? ''} ${line.description}`"
                :subtitle="`${pcs(line.qty)} pcs at ${ratePerM(line.rate_per_m)} /M`"
                :padded="false"
            >
                <template #actions>
                    <span class="text-sm font-semibold tnum text-ink-900">{{ money(line.line_total) }}</span>
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
                                    <td class="px-3 py-1.5 text-right tnum font-medium">{{ money(cl.amount) }}</td>
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
                            <div class="flex justify-between"><dt class="text-ink-500">Material</dt><dd class="tnum">{{ money(line.cost_sheet.material_cost) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-ink-500">Machine + labour + energy</dt><dd class="tnum">{{ money(Number(line.cost_sheet.machine_cost) + Number(line.cost_sheet.labour_cost) + Number(line.cost_sheet.energy_cost)) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-ink-500">Overheads</dt><dd class="tnum">{{ money(line.cost_sheet.overhead_amount) }}</dd></div>
                            <div class="flex justify-between border-t border-slate-200 pt-1.5"><dt class="font-medium">Total cost</dt><dd class="tnum font-medium">{{ money(line.cost_sheet.total_cost) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-ink-500">Unit cost</dt><dd class="tnum">{{ Number(line.cost_sheet.unit_cost).toFixed(6) }}</dd></div>
                            <div class="flex justify-between">
                                <dt class="text-ink-500">Margin <span class="font-mono text-[10px] text-ink-400">BR-20</span></dt>
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
                    <div><dt class="text-ink-500">Subtotal</dt><dd class="text-lg font-semibold tnum">{{ money(quotation.subtotal) }}</dd></div>
                    <div><dt class="text-ink-500">Tax</dt><dd class="text-lg font-semibold tnum">{{ money(quotation.tax_amount) }}</dd></div>
                    <div><dt class="text-ink-500">Total</dt><dd class="text-lg font-semibold tnum text-brand-800">{{ money(quotation.total) }}</dd></div>
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
