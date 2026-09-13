<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DocumentActions from '@/Components/Ui/DocumentActions.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, money, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/plugins/permissions';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';

const props = defineProps({
    money: { type: Object, default: null },
    applications: { type: Array, default: () => [] },
    refunds: { type: Array, default: () => [] },
    targets: { type: Array, default: () => [] },
    salesReturn: { type: Object, default: null },
    creditNote: { type: Object, required: true },
    invoice: { type: Object, default: null },
    availableTransitions: { type: Array, default: () => [] },
});

const confirmTransition = useTransitionConfirm();

async function transition(to) {
    if (!(await confirmTransition(to, props.creditNote.number))) return;

    router.post(`/credit-notes/${props.creditNote.id}/transition`, { to }, { preserveScroll: true });
}

/**
 * Applying credit to an invoice other than the one it came from, and paying it back.
 *
 * Both are offered only while the note has value left, and both are refused server-side under
 * the note's row lock — these dialogs ask for a figure, they do not decide anything.
 */
const applyOpen = ref(false);
const applyForm = useForm({ sales_invoice_id: '', amount: '' });

const refundOpen = ref(false);
const refundForm = useForm({ amount: '', method: 'bank_transfer', reference_no: '', reason: '' });

function applyCredit() {
    applyForm.post(`/credit-notes/${props.creditNote.id}/apply`, {
        preserveScroll: true,
        onSuccess: () => {
            applyOpen.value = false;
            applyForm.reset();
        },
    });
}

function refundCredit() {
    refundForm.post(`/credit-notes/${props.creditNote.id}/refund`, {
        preserveScroll: true,
        onSuccess: () => {
            refundOpen.value = false;
            refundForm.reset('amount', 'reference_no', 'reason');
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="creditNote.number ?? 'Credit note'" />

        <template #title>{{ creditNote.number ?? '(draft credit note)' }}</template>
        <template #subtitle>
            {{ creditNote.customer?.name }} · {{ date(creditNote.note_date) }} · {{ titleCase(creditNote.reason) }}
        </template>

        <template #actions>
            <Badge :status="creditNote.status" />
            <Button v-if="availableTransitions.includes('approved')" size="sm" variant="primary" @click="transition('approved')">
                Approve
            </Button>
            <Button v-if="availableTransitions.includes('applied')" size="sm" variant="primary" @click="transition('applied')">
                Apply to invoice
            </Button>
            <Button
                v-if="money && money.available > 0 && can('credit_note.apply') && creditNote.status === 'approved'"
                size="sm"
                variant="primary"
                @click="applyOpen = true"
            >
                Apply credit
            </Button>
            <Button
                v-if="money && money.available > 0 && can('credit_note.refund') && creditNote.status === 'approved'"
                size="sm"
                @click="refundOpen = true"
            >
                Refund
            </Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="transition('cancelled')">
                Cancel
            </Button>
            <DocumentActions document="credit-notes" :id="creditNote.id" :status="creditNote.status" />
        </template>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card title="Credit">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-xs text-ink-500">Amount</dt><dd class="font-medium tnum">{{ money(creditNote.amount, creditNote.currency) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Reason</dt><dd class="font-medium">{{ titleCase(creditNote.reason) }}</dd></div>
                </dl>
                <p v-if="creditNote.remarks" class="mt-3 whitespace-pre-line text-sm text-ink-600">{{ creditNote.remarks }}</p>
                <p class="mt-3 rounded bg-slate-50 px-2 py-1 text-xs text-ink-500">
                    Applying is the step that moves the invoice's arithmetic — checked against its live
                    outstanding balance under lock; over-crediting is refused there.
                </p>
            </Card>

            <Card v-if="invoice" title="Against invoice">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-xs text-ink-500">Invoice</dt>
                        <dd><Link :href="`/invoices/${invoice.id}`" class="doc-link-quiet">{{ invoice.number }}</Link> <Badge :status="invoice.status" /></dd></div>
                    <div><dt class="text-xs text-ink-500">Total</dt><dd class="font-medium tnum">{{ money(invoice.total, invoice.currency) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Received</dt><dd class="font-medium tnum text-emerald-700">{{ money(invoice.received_amount, invoice.currency) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Credited</dt><dd class="font-medium tnum text-amber-700">{{ money(invoice.credited, invoice.currency) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Outstanding</dt><dd class="font-medium tnum" :class="invoice.outstanding > 0 ? 'text-rose-600' : ''">{{ money(invoice.outstanding, invoice.currency) }}</dd></div>
                </dl>
            </Card>

            <!--
                What this note is worth, and where its value went. `Applied` and `Refunded` are
                consumption; the invoice panel above is provenance. Keeping them on the same
                screen but plainly apart is the whole point of the distinction.
            -->
            <Card v-if="money" title="This credit" subtitle="Where the value went — which is not the same question as where it came from">
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs text-ink-500">Amount</dt><dd class="font-medium tnum">{{ money.amount }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Applied</dt><dd class="font-medium tnum text-amber-700">{{ money.applied }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Refunded</dt><dd class="font-medium tnum text-rose-700">{{ money.refunded }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Available</dt><dd class="font-medium tnum text-emerald-700">{{ money.available }}</dd></div>
                </dl>

                <p v-if="salesReturn" class="mt-3 text-xs text-ink-600">
                    Raised by customer return
                    <Link :href="`/sales-returns/${salesReturn.id}`" class="doc-link-quiet">{{ salesReturn.number }}</Link>.
                    The invoice named above is where the goods were billed — it is not being credited.
                </p>
            </Card>

            <div v-if="applications.length || refunds.length" class="grid gap-4 lg:grid-cols-2">
                <Card v-if="applications.length" title="Applied to" :padded="false">
                    <DataTable
                        :columns="[
                            { key: 'number', label: 'Invoice' },
                            { key: 'applied_on', label: 'On' },
                            { key: 'amount', label: 'Amount', align: 'right' },
                        ]"
                        :rows="applications"
                        row-key="id"
                        dense
                        empty="None."
                    >
                        <template #cell:number="{ row, value }">
                            <Link :href="`/invoices/${row.invoice_id}`" class="doc-link-quiet">{{ value }}</Link>
                            <Badge :status="row.status" />
                        </template>
                    </DataTable>
                </Card>

                <Card v-if="refunds.length" title="Refunded" :padded="false">
                    <DataTable
                        :columns="[
                            { key: 'number', label: 'Refund' },
                            { key: 'method', label: 'Method' },
                            { key: 'refund_date', label: 'On' },
                            { key: 'amount', label: 'Amount', align: 'right' },
                        ]"
                        :rows="refunds"
                        row-key="id"
                        dense
                        empty="None."
                    />
                </Card>
            </div>
        </div>

        <Modal
            v-model:open="applyOpen"
            title="Apply this credit to an invoice"
            subtitle="Any open invoice of the same customer and currency — not necessarily the one it came from."
        >
            <div class="space-y-3">
                <FormField label="Invoice" :error="applyForm.errors.sales_invoice_id" required>
                    <SelectInput
                        v-model="applyForm.sales_invoice_id"
                        :options="targets.map((t) => ({
                            value: t.id,
                            label: `${t.number} · outstanding ${t.outstanding}`,
                            hint: t.status,
                        }))"
                        hint-key="hint"
                        placeholder="Which invoice should take this credit…"
                    />
                </FormField>
                <FormField
                    label="Amount"
                    :error="applyForm.errors.amount"
                    :hint="money ? `At most ${money.available}, and no more than that invoice still owes.` : null"
                    required
                >
                    <TextInput v-model="applyForm.amount" type="number" min="0" step="any" numeric />
                </FormField>
            </div>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="primary"
                    :disabled="!applyForm.sales_invoice_id || !applyForm.amount"
                    :loading="applyForm.processing"
                    @click="applyCredit"
                >
                    Apply
                </Button>
            </template>
        </Modal>

        <Modal
            v-model:open="refundOpen"
            title="Refund this credit to the customer"
            subtitle="Money leaving the bank. The credit note's approval is what authorises it."
        >
            <div class="space-y-3">
                <FormField
                    label="Amount"
                    :error="refundForm.errors.amount"
                    :hint="money ? `At most ${money.available}.` : null"
                    required
                >
                    <TextInput v-model="refundForm.amount" type="number" min="0" step="any" numeric />
                </FormField>
                <FormField label="Method" :error="refundForm.errors.method" required>
                    <SelectInput
                        v-model="refundForm.method"
                        :options="[
                            { value: 'bank_transfer', label: 'Bank transfer' },
                            { value: 'cheque', label: 'Cheque' },
                            { value: 'cash', label: 'Cash' },
                            { value: 'adjustment', label: 'Adjustment' },
                        ]"
                        :placeholder="null"
                    />
                </FormField>
                <FormField label="Reference" :error="refundForm.errors.reference_no">
                    <TextInput v-model="refundForm.reference_no" placeholder="Cheque or transfer reference…" />
                </FormField>
                <FormField label="Reason" :error="refundForm.errors.reason">
                    <TextInput v-model="refundForm.reason" placeholder="Customer asked for the money back rather than credit…" />
                </FormField>
            </div>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="primary"
                    :disabled="!refundForm.amount"
                    :loading="refundForm.processing"
                    @click="refundCredit"
                >
                    Post refund
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
