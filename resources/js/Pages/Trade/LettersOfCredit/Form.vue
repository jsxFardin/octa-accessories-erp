<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ResourceForm from '@/Components/Ui/ResourceForm.vue';
import { titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    letter: Object,
    suppliers: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.letter));

/** Past draft the bank owns the terms; the form says so rather than silently discarding them. */
const locked = computed(() => isEdit.value && props.letter.status !== 'draft');

const sections = computed(() => [
    {
        title: 'Credit',
        description: locked.value
            ? 'This credit has left draft. Commercial terms now move through an amendment, so only the bank reference and remarks save from here.'
            : 'Our number is allocated on save; the bank’s LC number arrives when the credit is opened.',
        fields: [
            {
                key: 'kind', label: 'Kind', type: 'select', required: true,
                options: props.kinds.map((k) => ({ value: k, label: titleCase(k) })),
                hint: 'TT, DA and DP are here too — not every import goes through a credit.',
            },
            {
                key: 'supplier_id', label: 'Supplier', type: 'select', required: true,
                options: props.suppliers.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` })),
            },
            { key: 'lc_no', label: 'Bank LC number', hint: 'Blank until the bank opens it.' },
            {
                key: 'bank_account_id', label: 'Issuing bank account', type: 'select',
                options: props.bankAccounts.map((b) => ({ value: b.id, label: `${b.code} · ${b.bank_name}` })),
            },
        ],
    },
    {
        title: 'Value',
        rule: 'BR-22',
        fields: [
            {
                key: 'currency_id', label: 'Currency', type: 'select', required: true,
                options: props.currencies.map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` })),
            },
            { key: 'exchange_rate', label: 'Exchange rate', type: 'number', step: '0.00000001', default: 1, rule: 'BR-22', hint: 'Snapshot at opening; the shipment carries its own.' },
            { key: 'amount', label: 'Amount', type: 'number', step: '0.0001', required: true },
            { key: 'tolerance_pct', label: 'Tolerance %', type: 'number', step: '0.01', hint: 'The +/- the bank will honour on the invoice value.' },
            { key: 'margin_pct', label: 'Margin %', type: 'number', step: '0.01', hint: 'Cash margin the bank holds.' },
            { key: 'tenor_days', label: 'Tenor (days)', type: 'number', hint: 'Zero for a sight credit.' },
            { key: 'charges_amount', label: 'Opening charges', type: 'number', step: '0.0001' },
        ],
    },
    {
        title: 'Dates & shipping',
        description: 'The bank will not accept a shipment after expiry, so neither does this form.',
        fields: [
            { key: 'applied_on', label: 'Applied on', type: 'date' },
            { key: 'issued_on', label: 'Issued on', type: 'date' },
            { key: 'last_shipment_date', label: 'Last shipment date', type: 'date' },
            { key: 'expiry_date', label: 'Expiry date', type: 'date' },
            { key: 'incoterm', label: 'Incoterm', hint: 'FOB, CFR, CIF…' },
            { key: 'port_of_loading', label: 'Port of loading' },
            { key: 'port_of_discharge', label: 'Port of discharge' },
            { key: 'remarks', label: 'Remarks', type: 'textarea', span: 'full' },
        ],
    },
]);
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${letter.number ?? 'credit'}` : 'New letter of credit'" />

        <template #title>{{ isEdit ? `Edit ${letter.number ?? 'credit'}` : 'New letter of credit' }}</template>
        <template #subtitle>{{ isEdit ? letter.lc_no ?? 'Not yet opened at the bank' : 'Raised against a supplier, covering one or more purchase orders' }}</template>

        <ResourceForm
            :sections="sections"
            :initial="letter ?? { exchange_rate: 1, kind: 'sight', tolerance_pct: 0, margin_pct: 0, tenor_days: 0, charges_amount: 0 }"
            :action="isEdit ? `/letters-of-credit/${letter.id}` : '/letters-of-credit'"
            :method="isEdit ? 'put' : 'post'"
            :submit-label="isEdit ? 'Save changes' : 'Create credit'"
            :cancel-href="isEdit ? `/letters-of-credit/${letter.id}` : '/letters-of-credit'"
        />
    </AppLayout>
</template>
