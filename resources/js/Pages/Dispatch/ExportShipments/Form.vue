<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ResourceForm from '@/Components/Ui/ResourceForm.vue';
import { titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    shipment: Object,
    /** Filled when arriving from a delivery note. */
    preselect: { type: Object, default: () => ({}) },
    customers: { type: Array, default: () => [] },
    letters: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    modes: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.shipment));
const shipped = computed(() => isEdit.value && !['draft', 'booked'].includes(props.shipment.status));

const sections = computed(() => [
    {
        title: 'Consignment',
        description: shipped.value ? 'Shipped: the customer and the credit are fixed; the transport and customs references can still be completed.' : 'The customer and the credit it ships under. Delivery notes are attached on the shipment itself.',
        fields: [
            { key: 'customer_id', label: 'Customer', type: 'select', required: true, disabled: shipped.value, options: props.customers.map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` })) },
            { key: 'lc_id', label: 'Export LC', type: 'select', disabled: shipped.value, hint: 'Blank for a TT or open-account shipment.', options: props.letters.map((l) => ({ value: l.id, label: `${l.number} · ${l.lc_no}` })) },
            { key: 'mode', label: 'Mode', type: 'select', required: true, options: props.modes.map((m) => ({ value: m, label: titleCase(m) })) },
            { key: 'forwarder', label: 'Freight forwarder' },
            { key: 'carrier', label: 'Shipping line / airline' },
            { key: 'vessel_or_flight', label: 'Vessel / flight' },
            { key: 'container_no', label: 'Container no.' },
            { key: 'seal_no', label: 'Seal no.' },
            { key: 'incoterm', label: 'Incoterm', hint: 'FOB, CFR, CIF…' },
        ],
    },
    {
        title: 'Route and dates',
        fields: [
            { key: 'port_of_loading', label: 'Port of loading' },
            { key: 'port_of_discharge', label: 'Port of discharge' },
            { key: 'final_destination', label: 'Final destination' },
            { key: 'etd', label: 'ETD', type: 'date' },
            { key: 'eta', label: 'ETA', type: 'date' },
        ],
    },
    {
        title: 'Customs and transport references',
        description: 'Usually known after the goods leave; each can also be recorded as a document on the shipment page.',
        fields: [
            { key: 'transport_doc_no', label: 'BL / AWB number' },
            { key: 'transport_doc_date', label: 'BL / AWB date', type: 'date' },
            { key: 'exp_form_no', label: 'EXP form no.' },
            { key: 'exp_form_date', label: 'EXP date', type: 'date' },
            { key: 'ud_no', label: 'UD no.', hint: 'Utilisation declaration, bonded buyers only.' },
        ],
    },
    {
        title: 'Value and weight',
        rule: 'BR-22',
        fields: [
            { key: 'currency_id', label: 'Currency', type: 'select', required: true, disabled: shipped.value, options: props.currencies.map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` })) },
            { key: 'exchange_rate', label: 'Exchange rate', type: 'number', step: '0.00000001', disabled: shipped.value, hint: 'Left blank, the reference rate on the ETD is booked.' },
            { key: 'goods_value', label: 'Goods value', type: 'number', step: '0.0001', default: 0 },
            { key: 'gross_kg', label: 'Gross weight (kg)', type: 'number', step: '0.001' },
            { key: 'net_kg', label: 'Net weight (kg)', type: 'number', step: '0.001' },
            { key: 'cbm', label: 'Volume (CBM)', type: 'number', step: '0.001' },
            { key: 'remarks', label: 'Remarks', type: 'textarea', span: 'full' },
        ],
    },
]);

const initial = computed(() => props.shipment ?? {
    mode: props.preselect?.mode ?? 'sea',
    goods_value: 0,
    customer_id: props.preselect?.customer_id ?? '',
    lc_id: props.preselect?.lc_id ?? '',
    currency_id: props.preselect?.currency_id ?? '',
    incoterm: props.preselect?.incoterm ?? '',
    port_of_loading: props.preselect?.port_of_loading ?? '',
    port_of_discharge: props.preselect?.port_of_discharge ?? '',
    challan_id: props.preselect?.challan_id ?? null,
});
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${shipment.number ?? 'shipment'}` : 'New export shipment'" />

        <template #title>{{ isEdit ? `Edit ${shipment.number ?? 'shipment'}` : 'New export shipment' }}</template>
        <template #subtitle>{{ preselect?.challan_id ? 'Opened from a delivery note, which is attached on save.' : 'The transport facts once, for every delivery note on the consignment' }}</template>

        <ResourceForm
            :sections="sections"
            :initial="initial"
            :rate-currencies="currencies"
            :existing="isEdit"
            :action="isEdit ? `/export-shipments/${shipment.id}` : '/export-shipments'"
            :method="isEdit ? 'put' : 'post'"
            :submit-label="isEdit ? 'Save changes' : 'Open shipment'"
            :cancel-href="isEdit ? `/export-shipments/${shipment.id}` : '/export-shipments'"
        />
    </AppLayout>
</template>
