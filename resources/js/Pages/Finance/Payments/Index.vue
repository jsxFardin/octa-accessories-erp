<script setup>
import { computed, onMounted, ref } from 'vue';
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
    allocations: [{ supplier_bill_id: null, amount: null }],
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

const chosenBill = computed(() => props.openBills.find(
    (bill) => bill.id === form.allocations[0].supplier_bill_id,
) ?? null);

/**
 * The server refuses an allocation above the payment or above the bill's outstanding, under a
 * row lock. This is the same arithmetic said early; the write still decides.
 */
const overAllocated = computed(() => {
    const allocation = Number(form.allocations[0].amount) || 0;

    if (allocation === 0) return null;
    if (Number(form.amount) && allocation > Number(form.amount)) {
        return 'More than the payment itself.';
    }
    if (chosenBill.value && allocation > Number(outstandingOf(chosenBill.value))) {
        return `More than this bill's ${money(outstandingOf(chosenBill.value), chosenBill.value.currency)} outstanding.`;
    }

    return null;
});

function pickBill(allocation) {
    const bill = props.openBills.find((row) => row.id === allocation.supplier_bill_id);

    if (bill) {
        form.supplier_id = bill.supplier_id;
        // BR-57 — a payment settles a bill in the bill's own currency, so choosing the bill
        // decides the currency. `??=` kept the first bill's currency after the choice changed,
        // which the server then (correctly) refused with no way to correct it from here.
        form.currency_id = bill.currency_id ?? form.currency_id;
        // Assigned, not defaulted: choosing a different document must not keep the first one's figure.
        allocation.amount = (Number(bill.total) - Number(bill.paid_amount)).toFixed(2);
        form.amount = allocation.amount;
    }
}

/**
 * Refusals that belong to no field on this dialog — a missing reference rate, a bill that
 * changed hands. Without this they came back from the server and were shown nowhere.
 */
const otherErrors = computed(() => ['exchange_rate', 'supplier_id', 'currency_id', 'allocations.0.supplier_bill_id', 'allocations.0.amount']
    .map((key) => form.errors[key])
    .filter(Boolean));

/**
 * Arriving from the document itself — "Record payment" on the bill — opens this dialog with it
 * already chosen. Before, the user came here, opened the dialog, and searched for the document
 * they had just been looking at.
 */
onMounted(() => {
    const id = Number(new URLSearchParams(window.location.search).get('bill'));

    if (!id || !props.openBills.some((row) => row.id === id)) return;

    form.allocations[0].supplier_bill_id = id;
    pickBill(form.allocations[0]);
    createOpen.value = true;
});

function submit() {
    form.post('/payments', {
        preserveScroll: true,
        onSuccess: () => {
            createOpen.value = false;
            form.reset();
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

            <DataTable :columns="columns" :rows="payments" row-key="id" empty="No payments.">
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

        <Modal v-model:open="createOpen" title="Record a payment" subtitle="Allocation cannot exceed the payment or a bill's outstanding balance" width="max-w-xl">
            <div class="flex flex-col gap-3">
                <div v-if="otherErrors.length" role="alert" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                    <p v-for="message in otherErrors" :key="message">{{ message }}</p>
                </div>
                <FormField
                    label="Supplier bill"
                    :error="form.errors.allocations"
                    required
                    :hint="chosenBill ? `${chosenBill.supplier_name} · ${money(outstandingOf(chosenBill), chosenBill.currency)} outstanding` : 'Searchable — type a bill number or a supplier.'"
                >
                    <SelectInput
                        v-model="form.allocations[0].supplier_bill_id"
                        :options="billOptions"
                        hint-key="hint"
                        placeholder="Choose an approved bill…"
                        @update:model-value="pickBill(form.allocations[0])"
                    />
                </FormField>
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Amount" :error="form.errors.amount" required>
                        <TextInput v-model="form.amount" type="number" min="0.01" step="any" numeric placeholder="0.00" />
                    </FormField>
                    <FormField
                        label="Allocate to bill"
                        required
                        :error="overAllocated"
                        hint="Defaults to the whole outstanding balance."
                    >
                        <TextInput v-model="form.allocations[0].amount" type="number" min="0.01" step="any" numeric placeholder="0.00" />
                    </FormField>
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
                <Button @click="createOpen = false">Back</Button>
                <Button
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing || !form.amount || !form.allocations[0].supplier_bill_id"
                    @click="submit"
                >Post payment</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
