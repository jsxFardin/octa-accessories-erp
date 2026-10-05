<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DocumentActions from '@/Components/Ui/DocumentActions.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, money, titleCase, todayIso } from '@/plugins/formatting';
import { allocatedTotal, allocationProblem, spreadOldestFirst } from '@/plugins/allocation';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    receipts: Object,
    filters: Object,
    openInvoices: { type: Array, default: () => [] },
});

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'customer', label: 'Customer' },
    { key: 'receipt_date', label: 'Date', sort: true },
    { key: 'method', label: 'Method' },
    { key: 'amount', label: 'Amount', align: 'right', sort: true },
    { key: 'allocated_amount', label: 'Allocated', align: 'right' },
    { key: 'status', label: 'Status' },
    // Receipts have no detail page: the money receipt itself is what someone comes here for,
    // so the document hangs off the row rather than off a screen that does not exist.
    { key: 'document', label: '', align: 'right' },
];

const METHODS = [
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'cash', label: 'Cash' },
    { value: 'cheque', label: 'Cheque' },
    { value: 'lc', label: 'LC' },
    { value: 'adjustment', label: 'Adjustment' },
];

const createOpen = ref(false);

const form = useForm({
    customer_id: null,
    receipt_date: todayIso(),
    method: 'bank_transfer',
    reference_no: '',
    currency_id: null,
    amount: null,
    allocations: [],
});

function outstandingOf(invoice) {
    return (Number(invoice.total) - Number(invoice.received_amount) - Number(invoice.credited_amount ?? 0)).toFixed(2);
}

// The picker narrows to the chosen invoice's customer; outstanding shown per invoice. The
// customer is the second line rather than a suffix, so a list of forty invoices scans by
// number down one column instead of by eye across a run-on string.
const invoiceOptions = computed(() => props.openInvoices.map((invoice) => ({
    value: invoice.id,
    label: `${invoice.number} · ${money(outstandingOf(invoice), invoice.currency)} outstanding`,
    hint: invoice.customer_name,
})));

/**
 * One receipt across several invoices.
 *
 * The dialog used to take a single invoice per receipt, so one cheque for five invoices was
 * several entries. Choosing a invoice now names the customer and lists every open invoice of
 * theirs in the same currency, oldest first, each with its own amount. "Oldest first" fills
 * them in from the amount received; any figure can then be changed by hand.
 */
const startInvoiceId = ref(null);
/** Amount set against each invoice, by id. */
const amounts = reactive({});

const candidates = computed(() => props.openInvoices
    .filter((row) => row.customer_id === form.customer_id && row.currency_id === form.currency_id)
    .map((row) => ({
        id: row.id,
        label: row.number,
        due_date: row.due_date,
        currency: row.currency,
        outstanding: Number(outstandingOf(row)),
    })));

const partyName = computed(
    () => props.openInvoices.find((row) => row.customer_id === form.customer_id)?.customer_name ?? null,
);
const currencyCode = computed(() => candidates.value[0]?.currency ?? null);

function clearAmounts() {
    Object.keys(amounts).forEach((key) => delete amounts[key]);
}

function pickInvoice(id) {
    const chosen = props.openInvoices.find((row) => row.id === id);

    if (!chosen) return;

    form.customer_id = chosen.customer_id;
    // BR-57 — money settles a invoice in the invoice's own currency, so the invoice decides it.
    form.currency_id = chosen.currency_id ?? form.currency_id;

    clearAmounts();
    amounts[id] = outstandingOf(chosen);
    form.amount = outstandingOf(chosen);
}

function spread() {
    const shares = spreadOldestFirst(form.amount, candidates.value);

    clearAmounts();
    Object.assign(amounts, shares);
}

const allocated = computed(() => allocatedTotal(amounts));
const unallocated = computed(() => Math.round(((Number(form.amount) || 0) - allocated.value) * 100) / 100);

/** What stops this being posted, in words. The server checks the same things under a lock. */
const blockedBy = computed(() => {
    if (!form.customer_id) return 'Choose an invoice to start.';
    if (!(Number(form.amount) > 0)) return 'Enter the amount received.';

    return allocationProblem(form.amount, amounts, candidates.value);
});

/*
 * Arriving from the invoice itself — "Record receipt" there — opens this dialog with it chosen.
 */
onMounted(() => {
    const id = Number(new URLSearchParams(window.location.search).get('invoice'));

    if (!id || !props.openInvoices.some((row) => row.id === id)) return;

    startInvoiceId.value = id;
    pickInvoice(id);
    createOpen.value = true;
});

/**
 * Refusals that belong to no field on this dialog — a missing reference rate, a document that
 * changed hands, a line of the allocation the server would not take. Shown at the top.
 */
const otherErrors = computed(() => Object.entries(form.errors)
    .filter(([key]) => ['exchange_rate', 'customer_id', 'currency_id'].includes(key) || key.startsWith('allocations.'))
    .map(([, message]) => message));

function submit() {
    form
        .transform((data) => ({
            ...data,
            allocations: Object.entries(amounts)
                .filter(([, amount]) => Number(amount) > 0)
                .map(([id, amount]) => ({ sales_invoice_id: Number(id), amount: Number(amount) })),
        }))
        .post('/receipts', {
            preserveScroll: true,
            onSuccess: () => {
                createOpen.value = false;
                form.reset();
                clearAmounts();
                startInvoiceId.value = null;
            },
        });
}
</script>

<template>
    <AppLayout>
        <Head title="Receipts" />

        <template #title>Receipts</template>
        <template #subtitle>Allocated against invoices; payment status derives from the money</template>

        <template #actions>
            <Button v-if="can('receipt.allocate')" size="sm" variant="primary" @click="createOpen = true">
                Record receipt
            </Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['posted','bounced','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search receipt number…" />

            <DataTable :columns="columns" :rows="receipts" row-key="id" empty="No receipts." :row-href="(row) => `/receipts/${row.id}`">
                <template #cell:receipt_date="{ value }">{{ date(value) }}</template>
                <template #cell:amount="{ row, value }">{{ money(value, row.currency) }}</template>
                <template #cell:allocated_amount="{ row, value }">{{ money(value, row.currency) }}</template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #cell:document="{ row }">
                    <DocumentActions document="receipts" :id="row.id" :status="row.status" />
                </template>
                <template #empty>
                    <EmptyState
                        icon="money"
                        title="Nothing received yet"
                        description="A receipt allocates money against issued invoices and moves them toward paid."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>

        <Modal v-model:open="createOpen" title="Record a receipt" subtitle="One receipt can settle several invoices. Whatever is not set against one stays on account." width="max-w-2xl">
            <div class="flex flex-col gap-3">
                <div v-if="otherErrors.length" role="alert" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                    <p v-for="message in otherErrors" :key="message">{{ message }}</p>
                </div>
                <FormField
                    label="Invoice"
                    required
                    :hint="partyName ? `${partyName} — every open invoice of theirs is listed below.` : 'Type a number or a customer name. Their other open invoices are then listed too.'"
                >
                    <SelectInput
                        v-model="startInvoiceId"
                        :options="invoiceOptions"
                        hint-key="hint"
                        placeholder="Choose an open invoice…"
                        @update:model-value="pickInvoice"
                    />
                </FormField>

                <FormField label="Amount received" :error="form.errors.amount" required>
                    <TextInput v-model="form.amount" type="number" min="0.01" step="any" numeric placeholder="0.00" />
                </FormField>

                <div v-if="candidates.length" class="rounded-md border border-slate-200">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2">
                        <p class="text-xs font-medium text-ink-800">Set against invoices</p>
                        <Button size="sm" :disabled="!(Number(form.amount) > 0)" @click="spread">Fill oldest first</Button>
                    </div>
                    <div class="max-h-56 overflow-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs text-ink-600">
                                <tr>
                                    <th scope="col" class="px-3 py-1.5 text-left font-medium">Invoice</th>
                                    <th scope="col" class="px-3 py-1.5 text-left font-medium">Due</th>
                                    <th scope="col" class="px-3 py-1.5 text-right font-medium">Outstanding</th>
                                    <th scope="col" class="w-32 px-3 py-1.5 text-right font-medium">This receipt</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="row in candidates" :key="row.id">
                                    <td class="px-3 py-1.5 font-medium text-ink-900">{{ row.label }}</td>
                                    <td class="px-3 py-1.5 text-ink-700">{{ date(row.due_date) }}</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ money(row.outstanding, row.currency) }}</td>
                                    <td class="px-3 py-1.5">
                                        <TextInput
                                            v-model="amounts[row.id]"
                                            type="number"
                                            min="0"
                                            step="any"
                                            numeric
                                            placeholder="0.00"
                                            :aria-label="`Amount against ${row.label}`"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="border-t border-slate-200 px-3 py-2 text-xs text-ink-700">
                        {{ money(allocated, currencyCode) }} set against invoices.
                        <span v-if="unallocated > 0">{{ money(unallocated, currencyCode) }} is not set against anything and stays on account.</span>
                    </p>
                </div>

                <p v-if="form.errors.allocations" role="alert" class="text-xs text-rose-700">{{ form.errors.allocations }}</p>

                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Date" :error="form.errors.receipt_date" required>
                        <DateInput v-model="form.receipt_date" :max="todayIso()" />
                    </FormField>
                    <FormField label="Method" :error="form.errors.method" required>
                        <SelectInput v-model="form.method" :options="METHODS" :placeholder="null" />
                    </FormField>
                    <FormField
                        label="Reference"
                        :error="form.errors.reference_no"
                        class="col-span-2"
                        hint="Cheque number, transfer reference — whatever the bank statement will show."
                    >
                        <TextInput v-model="form.reference_no" />
                    </FormField>
                </div>
            </div>
            <template #footer>
                <span v-if="blockedBy" id="invoice-blocked" class="mr-auto text-xs text-ink-600">{{ blockedBy }}</span>
                <Button @click="createOpen = false">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing || blockedBy !== null"
                    :aria-describedby="blockedBy ? 'invoice-blocked' : null"
                    @click="submit"
                >Post receipt</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
