<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ResourceForm from '@/Components/Ui/ResourceForm.vue';
import { titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    shipment: Object,
    suppliers: { type: Array, default: () => [] },
    letters: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    modes: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.shipment));

const sections = computed(() => [
    {
        title: 'Consignment',
        description: 'The supplier’s invoice and the transport document are what customs and the bank both quote.',
        fields: [
            {
                key: 'supplier_id', label: 'Supplier', type: 'select', required: true,
                options: props.suppliers.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` })),
            },
            {
                key: 'lc_id', label: 'Letter of credit', type: 'select',
                options: props.letters.map((l) => ({ value: l.id, label: l.lc_no ? `${l.number} · ${l.lc_no}` : l.number })),
                hint: 'Blank for a TT or DP shipment.',
            },
            { key: 'invoice_no', label: 'Supplier invoice no' },
            { key: 'invoice_date', label: 'Invoice date', type: 'date' },
            { key: 'transport_doc_no', label: 'BL / AWB number' },
            {
                key: 'mode', label: 'Mode', type: 'select', required: true,
                options: props.modes.map((m) => ({ value: m, label: titleCase(m) })),
            },
            { key: 'carrier', label: 'Carrier / vessel' },
            { key: 'incoterm', label: 'Incoterm' },
        ],
    },
    {
        title: 'Movement',
        fields: [
            { key: 'etd', label: 'ETD', type: 'date' },
            { key: 'eta', label: 'ETA', type: 'date' },
            { key: 'port_of_loading', label: 'Port of loading' },
            { key: 'port_of_discharge', label: 'Port of discharge' },
            { key: 'bill_of_entry', label: 'Bill of entry', hint: 'Stamped at clearance; can be left for later.' },
            { key: 'be_date', label: 'B/E date', type: 'date' },
        ],
    },
    {
        title: 'Value',
        rule: 'BR-22',
        description: 'Goods value in the supplier’s currency; every cost added later is converted to base with its own rate.',
        fields: [
            {
                key: 'currency_id', label: 'Currency', type: 'select', required: true,
                options: props.currencies.map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` })),
            },
            { key: 'exchange_rate', label: 'Exchange rate', type: 'number', step: '0.00000001', default: 1 },
            { key: 'goods_value', label: 'Goods value', type: 'number', step: '0.0001' },
            { key: 'remarks', label: 'Remarks', type: 'textarea', span: 'full' },
        ],
    },
]);
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${shipment.number ?? 'shipment'}` : 'New shipment'" />

        <template #title>{{ isEdit ? `Edit ${shipment.number}` : 'New import shipment' }}</template>
        <template #subtitle>Costs and goods receipts are attached on the shipment itself, once it exists</template>

        <ResourceForm
            :sections="sections"
            :initial="shipment ?? { exchange_rate: 1, mode: 'sea', goods_value: 0 }"
            :action="isEdit ? `/import-shipments/${shipment.id}` : '/import-shipments'"
            :method="isEdit ? 'put' : 'post'"
            :submit-label="isEdit ? 'Save changes' : 'Create shipment'"
            :cancel-href="isEdit ? `/import-shipments/${shipment.id}` : '/import-shipments'"
        />
    </AppLayout>
</template>
