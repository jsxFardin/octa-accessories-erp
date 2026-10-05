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
 * One receipt: what was taken, what it settled, and what has happened to it since.
 *
 * There was no such page. A receipt existed only as a row in a list, could not be opened, and —
 * once posted — could not be corrected: a bounced cheque or a receipt keyed in error stayed on
 * the books. From here it can be reversed, with a reason, and what it settled becomes owed again.
 */
const props = defineProps({
    receipt: { type: Object, required: true },
    allocations: { type: Array, default: () => [] },
});

const METHODS = { bank_transfer: 'Bank transfer', cash: 'Cash', cheque: 'Cheque', lc: 'Letter of credit', adjustment: 'Adjustment' };

/** Which reversal is being asked for — 'bounced', 'cancelled' — or null when the dialog is closed. */
const reversing = ref(null);
const form = useForm({ outcome: 'cancelled', reason: '' });

function open(outcome) {
    form.reset();
    form.clearErrors();
    form.outcome = outcome;
    reversing.value = outcome;
}

function submit() {
    form.post(`/receipts/${props.receipt.id}/reverse`, {
        preserveScroll: true,
        onSuccess: () => { reversing.value = null; },
    });
}
</script>

<template>
    <AppLayout :crumb="receipt.number">
        <Head :title="receipt.number" />

        <template #title>{{ receipt.number }}</template>
        <template #subtitle>
            Customer receipt · {{ receipt.customer?.name }} · {{ date(receipt.receipt_date) }}
        </template>

        <template #actions>
            <Badge :status="receipt.status" />
            <template v-if="receipt.status === 'posted' && can('receipt.delete')">
                <Button v-if="receipt.method === 'cheque'" size="sm" variant="danger" @click="open('bounced')">
                    Cheque bounced
                </Button>
                <Button size="sm" variant="danger" @click="open('cancelled')">Void receipt</Button>
            </template>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-1" title="Details">
                <dl class="space-y-2.5 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">Amount</dt>
                        <dd class="font-semibold tnum text-ink-900">{{ money(receipt.amount, receipt.currency) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">Set against invoices</dt>
                        <dd class="tnum text-ink-800">{{ money(receipt.allocated_amount, receipt.currency) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">Method</dt>
                        <dd class="text-ink-800">{{ METHODS[receipt.method] ?? receipt.method }}</dd>
                    </div>
                    <div v-if="receipt.reference_no" class="flex justify-between gap-3">
                        <dt class="text-ink-500">Reference</dt>
                        <dd class="text-ink-800">{{ receipt.reference_no }}</dd>
                    </div>
                    <div v-if="receipt.bank_name" class="flex justify-between gap-3">
                        <dt class="text-ink-500">Bank</dt>
                        <dd class="text-ink-800">{{ receipt.bank_name }}</dd>
                    </div>
                </dl>

                <p v-if="receipt.remarks" class="mt-3 border-t border-slate-100 pt-3 text-xs leading-relaxed whitespace-pre-line text-ink-700">
                    {{ receipt.remarks }}
                </p>
            </Card>

            <Card class="lg:col-span-2" :title="`Invoices this receipt settled`" :padded="false">
                <p
                    v-if="receipt.status !== 'posted'"
                    class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-900"
                >
                    This receipt was reversed. The amounts below were taken back off these invoices, which are owed again.
                </p>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-slate-200 text-xs text-ink-600">
                            <tr>
                                <th scope="col" class="px-4 py-2 text-left font-medium">Invoice</th>
                                <th scope="col" class="px-4 py-2 text-left font-medium">Invoice status now</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Invoice total</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Settled by this receipt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in allocations" :key="row.id">
                                <td class="px-4 py-2">
                                    <Link :href="`/invoices/${row.invoice_id}`" class="font-medium text-brand-700 hover:underline">
                                        {{ row.invoice_number }}
                                    </Link>
                                </td>
                                <td class="px-4 py-2"><Badge :status="row.invoice_status" /></td>
                                <td class="px-4 py-2 text-right tnum">{{ money(row.invoice_total, receipt.currency) }}</td>
                                <td class="px-4 py-2 text-right font-medium tnum">{{ money(row.amount, receipt.currency) }}</td>
                            </tr>
                            <tr v-if="allocations.length === 0">
                                <td colspan="4" class="px-4 py-6 text-center text-ink-600">Not set against any invoice.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <Modal
            :open="reversing !== null"
            :title="reversing === 'bounced' ? `Record that the cheque for ${receipt.number} bounced?` : `Void ${receipt.number}?`"
            subtitle="This cannot be undone."
            width="max-w-md"
            @update:open="(value) => { if (!value) reversing = null; }"
        >
            <p class="mb-3 text-sm leading-relaxed text-ink-700">
                {{ money(receipt.allocated_amount, receipt.currency) }} is taken back off
                {{ allocations.length === 1 ? 'the invoice' : `the ${allocations.length} invoices` }} this receipt settled,
                and {{ allocations.length === 1 ? 'it is' : 'they are' }} owed again. The receipt stays on record, marked
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
                    {{ reversing === 'bounced' ? 'Mark as bounced' : 'Void receipt' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
