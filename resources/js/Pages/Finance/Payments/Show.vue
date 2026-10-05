<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { date, money } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * One payment: what was paid, what it settled, and what has happened to it since.
 *
 * There was no such page. A payment existed only as a row in a list, could not be opened, and —
 * once posted — could not be corrected: a payment keyed in error stayed on
 * the books. From here it can be reversed, with a reason, and what it settled becomes owed again.
 */
const props = defineProps({
    payment: { type: Object, required: true },
    allocations: { type: Array, default: () => [] },
});

const METHODS = { bank_transfer: 'Bank transfer', cash: 'Cash', cheque: 'Cheque', lc: 'Letter of credit', adjustment: 'Adjustment' };

/** Which reversal is being asked for — 'cancelled' — or null when the dialog is closed. */
const reversing = ref(null);
const form = useForm({ outcome: 'cancelled', reason: '' });

function open(outcome) {
    form.reset();
    form.clearErrors();
    form.outcome = outcome;
    reversing.value = outcome;
}

function submit() {
    form.post(`/payments/${props.payment.id}/reverse`, {
        preserveScroll: true,
        onSuccess: () => { reversing.value = null; },
    });
}
</script>

<template>
    <AppLayout :crumb="payment.number">
        <Head :title="payment.number" />

        <template #title>{{ payment.number }}</template>
        <template #subtitle>
            Supplier payment · {{ payment.supplier?.name }} · {{ date(payment.payment_date) }}
        </template>

        <template #actions>
            <Badge :status="payment.status" />
            <template v-if="payment.status === 'posted' && can('payment.delete')">
                <Button size="sm" variant="danger" @click="open('cancelled')">Void payment</Button>
            </template>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-1" title="Details">
                <dl class="space-y-2.5 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">Amount</dt>
                        <dd class="font-semibold tnum text-ink-900">{{ money(payment.amount, payment.currency) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">Set against bills</dt>
                        <dd class="tnum text-ink-800">{{ money(payment.allocated_amount, payment.currency) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">Method</dt>
                        <dd class="text-ink-800">{{ METHODS[payment.method] ?? payment.method }}</dd>
                    </div>
                    <div v-if="payment.reference_no" class="flex justify-between gap-3">
                        <dt class="text-ink-500">Reference</dt>
                        <dd class="text-ink-800">{{ payment.reference_no }}</dd>
                    </div>
                </dl>

                <p v-if="payment.remarks" class="mt-3 border-t border-slate-100 pt-3 text-xs leading-relaxed whitespace-pre-line text-ink-700">
                    {{ payment.remarks }}
                </p>
            </Card>

            <Card class="lg:col-span-2" :title="`Bills this payment settled`" :padded="false">
                <p
                    v-if="payment.status !== 'posted'"
                    class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-900"
                >
                    This payment was reversed. The amounts below were taken back off these bills, which are owed again.
                </p>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-slate-200 text-xs text-ink-600">
                            <tr>
                                <th scope="col" class="px-4 py-2 text-left font-medium">Bill</th>
                                <th scope="col" class="px-4 py-2 text-left font-medium">Bill status now</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Bill total</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Settled by this payment</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in allocations" :key="row.id">
                                <td class="px-4 py-2">
                                    <Link :href="`/supplier-bills/${row.bill_id}`" class="font-medium text-brand-700 hover:underline">
                                        {{ row.bill_number ?? row.bill_no }}
                                    </Link>
                                </td>
                                <td class="px-4 py-2"><Badge :status="row.bill_status" /></td>
                                <td class="px-4 py-2 text-right tnum">{{ money(row.bill_total, payment.currency) }}</td>
                                <td class="px-4 py-2 text-right font-medium tnum">{{ money(row.amount, payment.currency) }}</td>
                            </tr>
                            <tr v-if="allocations.length === 0">
                                <td colspan="4" class="px-4 py-6 text-center text-ink-600">Not set against any bill.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <Modal
            :open="reversing !== null"
            :title="reversing === 'bounced' ? `Record that the cheque for ${payment.number} bounced?` : `Void ${payment.number}?`"
            subtitle="This cannot be undone."
            width="max-w-md"
            @update:open="(value) => { if (!value) reversing = null; }"
        >
            <p class="mb-3 text-sm leading-relaxed text-ink-700">
                {{ money(payment.allocated_amount, payment.currency) }} is taken back off
                {{ allocations.length === 1 ? 'the bill' : `the ${allocations.length} bills` }} this payment settled,
                and {{ allocations.length === 1 ? 'it is' : 'they are' }} owed again. The payment stays on record, marked
                {{ reversing === 'bounced' ? 'bounced' : 'cancelled' }}.
            </p>

            <FormField
                label="Reason"
                :hint="reversing === 'bounced' ? 'What the bank said, and the date on the return memo.' : 'What was wrong with it.'"
                :error="form.errors.reason"
                required
            >
                <textarea v-model="form.reason" rows="2" class="form-textarea" />
            </FormField>

            <p v-if="form.reason.trim().length < 5" id="reverse-reason-hint" class="mt-2 text-xs text-ink-600">
                Give a reason of at least 5 characters.
            </p>

            <template #footer>
                <Button @click="reversing = null">Keep it</Button>
                <Button
                    variant="danger"
                    :loading="form.processing"
                    :disabled="form.processing || form.reason.trim().length < 5"
                    :aria-describedby="form.reason.trim().length < 5 ? 'reverse-reason-hint' : null"
                    @click="submit"
                >
                    {{ reversing === 'bounced' ? 'Mark as bounced' : 'Void payment' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
