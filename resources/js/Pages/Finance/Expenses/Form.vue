<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ResourceForm from '@/Components/Ui/ResourceForm.vue';
import { titleCase, todayIso } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    expense: Object,
    categories: { type: Array, default: () => [] },
    factoryUnits: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    shipments: { type: Array, default: () => [] },
    methods: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.expense));

const sections = computed(() => [
    {
        title: 'Spend',
        description: 'One amount, one category, one payee. Anything more complicated is a purchase order.',
        fields: [
            { key: 'expense_date', label: 'Date', type: 'date', required: true },
            {
                key: 'expense_category_id', label: 'Category', type: 'select', required: true,
                options: props.categories.map((c) => ({ value: c.id, label: `${c.name} · ${titleCase(c.kind)}` })),
                hint: 'Chosen, never typed — otherwise the quarter cannot be summed.',
            },
            { key: 'payee', label: 'Paid to', required: true, span: 'full' },
            { key: 'description', label: 'What for', type: 'textarea', span: 'full' },
        ],
    },
    {
        title: 'Where it belongs',
        description: 'Optional, but it is what turns a cash book into a cost report.',
        fields: [
            {
                key: 'factory_unit_id', label: 'Factory unit', type: 'select',
                options: props.factoryUnits.map((u) => ({ value: u.id, label: u.name })),
            },
            {
                key: 'department_id', label: 'Department', type: 'select',
                options: props.departments.map((d) => ({ value: d.id, label: d.name })),
            },
            {
                key: 'supplier_id', label: 'Supplier', type: 'select',
                options: props.suppliers.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` })),
            },
            {
                key: 'import_shipment_id', label: 'Import shipment', type: 'select',
                options: props.shipments.map((s) => ({ value: s.id, label: s.invoice_no ? `${s.number} · ${s.invoice_no}` : s.number })),
                // The costing link is the import_costs row, not this one; said here so nobody
                // expects a lot cost to move because an expense named a shipment.
                hint: 'For reporting. To put this into stock cost, add it as a cost on the shipment.',
            },
        ],
    },
    {
        title: 'Payment',
        rule: 'BR-22',
        fields: [
            {
                key: 'currency_id', label: 'Currency', type: 'select', required: true,
                options: props.currencies.map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` })),
            },
            { key: 'exchange_rate', label: 'Exchange rate', type: 'number', step: '0.00000001', default: 1 },
            { key: 'amount', label: 'Amount', type: 'number', step: '0.0001', required: true },
            { key: 'tax_amount', label: 'VAT / tax', type: 'number', step: '0.0001' },
            {
                key: 'method', label: 'Method', type: 'select', required: true,
                options: props.methods.map((m) => ({ value: m, label: titleCase(m) })),
            },
            {
                key: 'bank_account_id', label: 'Bank account', type: 'select',
                options: props.bankAccounts.map((b) => ({ value: b.id, label: `${b.code} · ${b.name}` })),
            },
            { key: 'reference_no', label: 'Cheque / reference no' },
        ],
    },
]);
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${expense.number ?? 'expense'}` : 'New expense'" />

        <template #title>{{ isEdit ? `Edit ${expense.number}` : 'New expense' }}</template>
        <template #subtitle>Saved as a draft; approval is a separate act by somebody else</template>

        <ResourceForm
            :sections="sections"
            :initial="expense ?? {
                exchange_rate: 1,
                method: 'cash',
                tax_amount: 0,
                expense_date: todayIso(),
            }"
            :action="isEdit ? `/expenses/${expense.id}` : '/expenses'"
            :method="isEdit ? 'put' : 'post'"
            :submit-label="isEdit ? 'Save changes' : 'Create expense'"
            cancel-href="/expenses"
        />
    </AppLayout>
</template>
