<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
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
    payments: Object,
    filters: Object,
    openBills: { type: Array, default: () => [] },
});

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'supplier', label: 'Supplier' },
    { key: 'payment_date', label: 'Date', sort: true },
    { key: 'method', label: 'Method' },
    { key: 'amount', label: 'Amount', align: 'right', sort: true },
    { key: 'allocated_amount', label: 'Allocated', align: 'right' },
    { key: 'status', label: 'Status' },
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
    supplier_id: null,
    payment_date: todayIso(),
    method: 'bank_transfer',
    reference_no: '',
    // No exchange rate is sent. The server books the payment at the reference rate for the
    // bill's currency and date; a hard-coded 1 here was refused for every foreign-currency bill.
    currency_id: null,
    amount: null,
    remarks: '',
    allocations: [],
});

function outstandingOf(bill) {
    return (Number(bill.total) - Number(bill.paid_amount)).toFixed(2);
}

// Supplier on the second line: bills are found by their own number far more often than by
// the supplier's name, and a run-on label makes both harder to scan.
const billOptions = computed(() => props.openBills.map((bill) => ({
    value: bill.id,
    label: `${bill.number ?? bill.bill_no} · ${money(outstandingOf(bill), bill.currency)} outstanding`,
    hint: bill.supplier_name,
})));

/**
 * One payment across several bills.
 *
 * The dialog used to take a single bill per payment, so one transfer for five bills was
 * several entries. Choosing a bill now names the supplier and lists every open bill of
 * theirs in the same currency, oldest first, each with its own amount. "Oldest first" fills
 * them in from the amount paid; any figure can then be changed by hand.
 */
const startBillId = ref(null);
/** Amount set against each bill, by id. */
const amounts = reactive({});

const candidates = computed(() => props.openBills
    .filter((row) => row.supplier_id === form.supplier_id && row.currency_id === form.currency_id)
    .map((row) => ({
        id: row.id,
        label: row.number ?? row.bill_no,
        due_date: row.due_date,
        currency: row.currency,
        outstanding: Number(outstandingOf(row)),
    })));

const partyName = computed(
    () => props.openBills.find((row) => row.supplier_id === form.supplier_id)?.supplier_name ?? null,
);
const currencyCode = computed(() => candidates.value[0]?.currency ?? null);

function clearAmounts() {
    Object.keys(amounts).forEach((key) => delete amounts[key]);
}

function pickBill(id) {
    const chosen = props.openBills.find((row) => row.id === id);

    if (!chosen) return;

    form.supplier_id = chosen.supplier_id;
    // BR-57 — money settles a bill in the bill's own currency, so the bill decides it.
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
    if (!form.supplier_id) return 'Choose a bill to start.';
    if (!(Number(form.amount) > 0)) return 'Enter the amount paid.';

    return allocationProblem(form.amount, amounts, candidates.value);
});

/*
 * Arriving from the bill itself — "Record payment" there — opens this dialog with it chosen.
 */
onMounted(() => {
    const id = Number(new URLSearchParams(window.location.search).get('bill'));

    if (!id || !props.openBills.some((row) => row.id === id)) return;

    startBillId.value = id;
    pickBill(id);
    createOpen.value = true;
});

/**
 * Refusals that belong to no field on this dialog — a missing reference rate, a document that
 * changed hands, a line of the allocation the server would not take. Shown at the top.
 */
const otherErrors = computed(() => Object.entries(form.errors)
    .filter(([key]) => ['exchange_rate', 'supplier_id', 'currency_id'].includes(key) || key.startsWith('allocations.'))
    .map(([, message]) => message));

function submit() {
    form
        .transform((data) => ({
            ...data,
            allocations: Object.entries(amounts)
                .filter(([, amount]) => Number(amount) > 0)
                .map(([id, amount]) => ({ supplier_bill_id: Number(id), amount: Number(amount) })),
        }))
        .post('/payments', {
            preserveScroll: true,
            onSuccess: () => {
                createOpen.value = false;
                form.reset();
                clearAmounts();
                startBillId.value = null;
            },
        });
}
</script>

<template>
    <AppLayout>
        <Head title="Payments" />

        <template #title>Payments</template>
        <template #subtitle>Allocated against approved supplier bills</template>

        <template #actions>
            <Button v-if="can('payment.allocate')" size="sm" variant="primary" @click="createOpen = true">
                Record payment
            </Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['posted','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search payment number…" />

            <DataTable :columns="columns" :rows="payments" row-key="id" empty="No payments." :row-href="(row) => `/payments/${row.id}`">
                <template #cell:payment_date="{ value }">{{ date(value) }}</template>
                <template #cell:amount="{ row, value }">{{ money(value, row.currency) }}</template>
                <template #cell:allocated_amount="{ row, value }">{{ money(value, row.currency) }}</template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="money"
                        title="No payments yet"
                        description="A payment allocates money against approved supplier bills and moves them toward paid."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>

        <Modal v-model:open="createOpen" title="Record a payment" subtitle="One payment can settle several bills. Whatever is not set against one stays on account." width="max-w-2xl">
            <div class="flex flex-col gap-3">
                <div v-if="otherErrors.length" role="alert" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                    <p v-for="message in otherErrors" :key="message">{{ message }}</p>
                </div>
                <FormField
                    label="Supplier bill"
                    required
                    :hint="partyName ? `${partyName} — every open bill of theirs is listed below.` : 'Type a number or a supplier name. Their other open bills are then listed too.'"
                >
                    <SelectInput
                        v-model="startBillId"
                        :options="billOptions"
                        hint-key="hint"
                        placeholder="Choose an approved bill…"
                        @update:model-value="pickBill"
                    />
                </FormField>

                <FormField label="Amount paid" :error="form.errors.amount" required>
                    <TextInput v-model="form.amount" type="number" min="0.01" step="any" numeric placeholder="0.00" />
                </FormField>

                <div v-if="candidates.length" class="rounded-md border border-slate-200">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2">
                        <p class="text-xs font-medium text-ink-800">Set against bills</p>
                        <Button size="sm" :disabled="!(Number(form.amount) > 0)" @click="spread">Fill oldest first</Button>
                    </div>
                    <div class="max-h-56 overflow-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs text-ink-600">
                                <tr>
                                    <th scope="col" class="px-3 py-1.5 text-left font-medium">Bill</th>
                                    <th scope="col" class="px-3 py-1.5 text-left font-medium">Due</th>
                                    <th scope="col" class="px-3 py-1.5 text-right font-medium">Outstanding</th>
                                    <th scope="col" class="w-32 px-3 py-1.5 text-right font-medium">This payment</th>
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
                        {{ money(allocated, currencyCode) }} set against bills.
                        <span v-if="unallocated > 0">{{ money(unallocated, currencyCode) }} is not set against anything and stays on account.</span>
                    </p>
                </div>

                <p v-if="form.errors.allocations" role="alert" class="text-xs text-rose-700">{{ form.errors.allocations }}</p>

                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Date" :error="form.errors.payment_date" required>
                        <DateInput v-model="form.payment_date" :max="todayIso()" />
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
                    <FormField label="Remarks" :error="form.errors.remarks" class="col-span-2">
                        <TextInput v-model="form.remarks" />
                    </FormField>
                </div>
            </div>
            <template #footer>
                <span v-if="blockedBy" id="bill-blocked" class="mr-auto text-xs text-ink-600">{{ blockedBy }}</span>
                <Button @click="createOpen = false">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing || blockedBy !== null"
                    :aria-describedby="blockedBy ? 'bill-blocked' : null"
                    @click="submit"
                >Post payment</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
