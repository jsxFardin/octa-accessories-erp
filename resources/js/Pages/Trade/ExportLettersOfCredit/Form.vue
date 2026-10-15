<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ResourceForm from '@/Components/Ui/ResourceForm.vue';
import { titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    letter: Object,
    preselectCustomerId: { type: Number, default: null },
    customers: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
    presentationDays: { type: Number, default: 21 },
});

const isEdit = computed(() => Boolean(props.letter));

/** Once accepted the terms are the bank's; only the advising side and the remarks still save. */
const locked = computed(() => isEdit.value && props.letter.status !== 'advised');
const STILL_EDITABLE = ['issuing_bank', 'advising_bank_account_id', 'charges_amount', 'remarks'];

const KIND_LABELS = { sight: 'LC at sight', usance: 'LC usance (deferred payment)', tt: 'Bank transfer (TT)', da: 'Documents against acceptance (DA)', dp: 'Documents against payment (DP)' };

const layout = computed(() => [
    {
        title: 'Credit',
        description: locked.value
            ? 'The credit has been accepted, so its terms change only through an amendment. The banks, the charges and the remarks can still be edited.'
            : "The customer's bank has already issued this credit, so its number is known now. Our own number is given on save.",
        fields: [
            { key: 'lc_no', label: 'Bank LC number', required: true, hint: 'As printed on the advice; every shipping document quotes it.' },
            { key: 'kind', label: 'Kind', type: 'select', required: true, options: props.kinds.map((k) => ({ value: k, label: KIND_LABELS[k] ?? titleCase(k) })) },
            { key: 'customer_id', label: 'Customer (applicant)', type: 'select', required: true, options: props.customers.map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` })) },
            { key: 'issuing_bank', label: 'Issuing bank', hint: "The customer's bank." },
            { key: 'advising_bank_account_id', label: 'Advising bank account', type: 'select', hint: 'Our account the proceeds will land in.', options: props.bankAccounts.map((b) => ({ value: b.id, label: `${b.code} · ${b.bank_name}` })) },
        ],
    },
    {
        title: 'Value',
        rule: 'BR-22 · BR-56',
        fields: [
            { key: 'currency_id', label: 'Currency', type: 'select', required: true, options: props.currencies.map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` })), hint: 'Only sales orders in this currency can be covered by the credit.' },
            { key: 'exchange_rate', label: 'Exchange rate', type: 'number', step: '0.00000001', rule: 'BR-22', hint: 'Left blank, the reference rate on the issue date is booked.' },
            { key: 'amount', label: 'Amount', type: 'number', step: '0.0001', required: true },
            { key: 'tolerance_pct', label: 'Tolerance %', type: 'number', step: '0.01', default: 0, hint: 'The +/- the bank will honour on the invoice value.' },
            { key: 'tenor_days', label: 'Tenor (days)', type: 'number', default: 0, hint: 'Zero for a sight credit.' },
            { key: 'charges_amount', label: 'Advising charges', type: 'number', step: '0.0001', default: 0 },
        ],
    },
    {
        title: 'Dates & shipping',
        description: 'The bank will not accept a shipment after expiry, so neither does this form.',
        fields: [
            { key: 'issued_on', label: 'Issued on', type: 'date' },
            { key: 'last_shipment_date', label: 'Last shipment date', type: 'date' },
            { key: 'expiry_date', label: 'Expiry date', type: 'date' },
            { key: 'latest_presentation_days', label: 'Presentation period (days)', type: 'number', default: props.presentationDays, hint: 'Days after shipment the documents must reach the bank.' },
            { key: 'partial_shipment', label: 'Partial shipment', type: 'checkbox', default: true, checkboxLabel: 'Allowed' },
            { key: 'transhipment', label: 'Transhipment', type: 'checkbox', default: true, checkboxLabel: 'Allowed' },
            { key: 'incoterm', label: 'Incoterm', hint: 'FOB, CFR, CIF…' },
            { key: 'port_of_loading', label: 'Port of loading' },
            { key: 'port_of_discharge', label: 'Port of discharge' },
            { key: 'remarks', label: 'Remarks', type: 'textarea', span: 'full' },
        ],
    },
]);

const sections = computed(() => layout.value.map((section) => ({
    ...section,
    fields: section.fields.map((field) => ({ ...field, disabled: locked.value && !STILL_EDITABLE.includes(field.key) })),
})));

const initial = computed(() => props.letter ?? {
    kind: 'sight', tolerance_pct: 0, tenor_days: 0, charges_amount: 0, latest_presentation_days: props.presentationDays,
    partial_shipment: true, transhipment: true, customer_id: props.preselectCustomerId ?? '',
});
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${letter.number}` : 'New export LC'" />

        <template #title>{{ isEdit ? `Edit ${letter.number}` : 'New export letter of credit' }}</template>
        <template #subtitle>{{ isEdit ? `Bank no. ${letter.lc_no}` : "Opened by a customer's bank in our favour, covering one or more sales orders" }}</template>

        <ResourceForm
            :sections="sections"
            :initial="initial"
            :action="isEdit ? `/export-lcs/${letter.id}` : '/export-lcs'"
            :method="isEdit ? 'put' : 'post'"
            :submit-label="isEdit ? 'Save changes' : 'Record credit'"
            :cancel-href="isEdit ? `/export-lcs/${letter.id}` : '/export-lcs'"
        />
    </AppLayout>
</template>
