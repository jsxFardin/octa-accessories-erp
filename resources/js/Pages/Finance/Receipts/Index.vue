<script setup>
import { computed, ref } from 'vue';
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
    allocations: [{ sales_invoice_id: null, amount: null }],
});

function outstandingOf(invoice) {
    return (Number(invoice.total) - Number(invoice.received_amount) - Number(invoice.credited_amount ?? 0)).toFixed(2);
}

// The picker narrows to the chosen invoice's customer; outstanding shown per invoice. The
// customer is the second line rather than a suffix, so a list of forty invoices scans by
// number down one column instead of by eye across a run-on string.
const invoiceOptions = computed(() => props.openInvoices.map((invoice) => ({
    value: invoice.id,
    label: `${invoice.number} · ${outstandingOf(invoice)} outstanding`,
    hint: invoice.customer_name,
})));

const chosenInvoice = computed(() => props.openInvoices.find(
    (invoice) => invoice.id === form.allocations[0].sales_invoice_id,
) ?? null);

/**
 * P2-1 — the server refuses an allocation above the receipt or above the invoice's
 * outstanding, under a row lock. This is the same arithmetic said early; the write still
 * decides.
 */
const overAllocated = computed(() => {
    const allocation = Number(form.allocations[0].amount) || 0;

    if (allocation === 0) return null;
    if (Number(form.amount) && allocation > Number(form.amount)) {
        return 'More than the receipt itself.';
    }
    if (chosenInvoice.value && allocation > Number(outstandingOf(chosenInvoice.value))) {
        return `More than this invoice's ${outstandingOf(chosenInvoice.value)} outstanding.`;
    }

    return null;
});

function pickInvoice(allocation) {
    const invoice = props.openInvoices.find((row) => row.id === allocation.sales_invoice_id);

    if (invoice) {
        form.customer_id = invoice.customer_id;
        form.currency_id ??= invoice.currency_id ?? null;
        allocation.amount ??= (Number(invoice.total) - Number(invoice.received_amount) - Number(invoice.credited_amount ?? 0)).toFixed(2);
        form.amount ??= allocation.amount;
    }
}

function submit() {
    form.post('/receipts', {
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

            <DataTable :columns="columns" :rows="receipts" row-key="id" empty="No receipts.">
                <template #cell:receipt_date="{ value }">{{ date(value) }}</template>
                <template #cell:amount="{ value }">{{ money(value) }}</template>
                <template #cell:allocated_amount="{ value }">{{ money(value) }}</template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="money"
                        title="Nothing received yet"
                        description="A receipt allocates money against issued invoices and moves them toward paid."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>

        <Modal v-model:open="createOpen" title="Record a receipt" subtitle="Allocation cannot exceed the receipt or an invoice's outstanding balance" width="max-w-xl">
            <div class="flex flex-col gap-3">
                <FormField
                    label="Invoice"
                    :error="form.errors.allocations"
                    required
                    :hint="chosenInvoice ? `${chosenInvoice.customer_name} · ${outstandingOf(chosenInvoice)} outstanding` : 'Searchable — type a number or a customer.'"
                >
                    <SelectInput
                        v-model="form.allocations[0].sales_invoice_id"
                        :options="invoiceOptions"
                        hint-key="hint"
                        placeholder="Choose an open invoice…"
                        @update:model-value="pickInvoice(form.allocations[0])"
                    />
                </FormField>
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Amount received" :error="form.errors.amount" required>
                        <TextInput v-model="form.amount" type="number" min="0.01" step="any" numeric placeholder="0.00" />
                    </FormField>
                    <FormField
                        label="Allocate to invoice"
                        required
                        :error="overAllocated"
                        hint="Defaults to the whole outstanding balance."
                    >
                        <TextInput v-model="form.allocations[0].amount" type="number" min="0.01" step="any" numeric placeholder="0.00" />
                    </FormField>
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
                <Button @click="createOpen = false">Back</Button>
                <Button
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing || !form.amount || !form.allocations[0].sales_invoice_id"
                    @click="submit"
                >Post receipt</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
