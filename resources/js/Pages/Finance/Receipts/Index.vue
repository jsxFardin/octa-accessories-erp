<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { date, money, titleCase } from '@/plugins/formatting';
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

const createOpen = ref(false);

const form = useForm({
    customer_id: null,
    receipt_date: new Date().toISOString().slice(0, 10),
    method: 'bank_transfer',
    reference_no: '',
    currency_id: null,
    amount: null,
    allocations: [{ sales_invoice_id: null, amount: null }],
});

// The picker narrows to the chosen invoice's customer; outstanding shown per invoice.
const invoiceOptions = computed(() => props.openInvoices.map((invoice) => ({
    ...invoice,
    outstanding: (Number(invoice.total) - Number(invoice.received_amount)).toFixed(2),
})));

function pickInvoice(allocation) {
    const invoice = props.openInvoices.find((row) => row.id === allocation.sales_invoice_id);

    if (invoice) {
        form.customer_id = invoice.customer_id;
        form.currency_id ??= invoice.currency_id ?? null;
        allocation.amount ??= (Number(invoice.total) - Number(invoice.received_amount)).toFixed(2);
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
                <FormField label="Invoice" :error="form.errors.allocations">
                    <select
                        v-model="form.allocations[0].sales_invoice_id"
                        class="w-full rounded-md border-slate-300 text-sm"
                        @change="pickInvoice(form.allocations[0])"
                    >
                        <option :value="null" disabled>Choose an open invoice…</option>
                        <option v-for="invoice in invoiceOptions" :key="invoice.id" :value="invoice.id">
                            {{ invoice.number }} — {{ invoice.customer_name }} · {{ invoice.outstanding }} outstanding
                        </option>
                    </select>
                </FormField>
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Amount received" :error="form.errors.amount" required>
                        <input v-model="form.amount" type="number" min="0.01" step="any" class="w-full rounded-md border-slate-300 text-sm" />
                    </FormField>
                    <FormField label="Allocate to invoice" required>
                        <input v-model="form.allocations[0].amount" type="number" min="0.01" step="any" class="w-full rounded-md border-slate-300 text-sm" />
                    </FormField>
                    <FormField label="Date" :error="form.errors.receipt_date" required>
                        <input v-model="form.receipt_date" type="date" class="w-full rounded-md border-slate-300 text-sm" />
                    </FormField>
                    <FormField label="Method" :error="form.errors.method" required>
                        <select v-model="form.method" class="w-full rounded-md border-slate-300 text-sm">
                            <option value="bank_transfer">Bank transfer</option>
                            <option value="cash">Cash</option>
                            <option value="cheque">Cheque</option>
                            <option value="lc">LC</option>
                            <option value="adjustment">Adjustment</option>
                        </select>
                    </FormField>
                    <FormField label="Reference" :error="form.errors.reference_no" class="col-span-2">
                        <input v-model="form.reference_no" type="text" class="w-full rounded-md border-slate-300 text-sm" />
                    </FormField>
                </div>
            </div>
            <template #footer>
                <Button @click="createOpen = false">Back</Button>
                <Button variant="primary" :disabled="form.processing" @click="submit">Post receipt</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
