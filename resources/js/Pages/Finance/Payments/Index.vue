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

const createOpen = ref(false);

const form = useForm({
    supplier_id: null,
    payment_date: todayIso(),
    method: 'bank_transfer',
    reference_no: '',
    currency_id: null,
    exchange_rate: 1,
    amount: null,
    remarks: '',
    allocations: [{ supplier_bill_id: null, amount: null }],
});

const billOptions = computed(() => props.openBills.map((bill) => ({
    ...bill,
    outstanding: (Number(bill.total) - Number(bill.paid_amount)).toFixed(2),
})));

function pickBill(allocation) {
    const bill = props.openBills.find((row) => row.id === allocation.supplier_bill_id);

    if (bill) {
        form.supplier_id = bill.supplier_id;
        form.currency_id ??= bill.currency_id ?? null;
        allocation.amount ??= (Number(bill.total) - Number(bill.paid_amount)).toFixed(2);
        form.amount ??= allocation.amount;
    }
}

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
                <template #cell:amount="{ value }">{{ money(value) }}</template>
                <template #cell:allocated_amount="{ value }">{{ money(value) }}</template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="money"
                        title="No payments yet"
                        description="A payment allocates money against approved supplier bills and moves them toward paid."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>

        <Modal v-model:open="createOpen" title="Record a payment" subtitle="Allocation cannot exceed the payment or a bill's outstanding balance" width="max-w-xl">
            <div class="flex flex-col gap-3">
                <FormField label="Supplier bill" :error="form.errors.allocations">
                    <select
                        v-model="form.allocations[0].supplier_bill_id"
                        class="w-full rounded-md border-slate-300 text-sm"
                        @change="pickBill(form.allocations[0])"
                    >
                        <option :value="null" disabled>Choose an approved bill…</option>
                        <option v-for="bill in billOptions" :key="bill.id" :value="bill.id">
                            {{ bill.number ?? bill.bill_no }} — {{ bill.supplier_name }} · {{ bill.outstanding }} outstanding
                        </option>
                    </select>
                </FormField>
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Amount" :error="form.errors.amount" required>
                        <input v-model="form.amount" type="number" min="0.01" step="any" class="w-full rounded-md border-slate-300 text-sm" />
                    </FormField>
                    <FormField label="Allocate to bill" required>
                        <input v-model="form.allocations[0].amount" type="number" min="0.01" step="any" class="w-full rounded-md border-slate-300 text-sm" />
                    </FormField>
                    <FormField label="Date" :error="form.errors.payment_date" required>
                        <input v-model="form.payment_date" type="date" class="w-full rounded-md border-slate-300 text-sm" />
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
                    <FormField label="Remarks" :error="form.errors.remarks" class="col-span-2">
                        <input v-model="form.remarks" type="text" class="w-full rounded-md border-slate-300 text-sm" />
                    </FormField>
                </div>
            </div>
            <template #footer>
                <Button @click="createOpen = false">Back</Button>
                <Button variant="primary" :disabled="form.processing" @click="submit">Post payment</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
