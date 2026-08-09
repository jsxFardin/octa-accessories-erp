<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ResourceForm from '@/Components/Ui/ResourceForm.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ customer: Object, paymentTerms: Array, currencies: Array });

const isEdit = computed(() => Boolean(props.customer));

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'code', label: 'Code', required: true },
            { key: 'name', label: 'Name', required: true },
            { key: 'kind', label: 'Kind', type: 'select', default: 'manufacturer', options: [
                { value: 'manufacturer', label: 'Garment manufacturer' },
                { value: 'brand', label: 'Brand' },
                { value: 'buying_house', label: 'Buying house' },
                { value: 'trader', label: 'Trader' },
            ] },
            { key: 'email', label: 'Email', type: 'email' },
            { key: 'phone', label: 'Phone' },
            { key: 'bin_no', label: 'BIN' },
            { key: 'tin_no', label: 'TIN' },
        ],
    },
    {
        title: 'Commercial guard rails',
        rule: 'BR-21 · BR-44 · BR-46',
        fields: [
            { key: 'currency_id', label: 'Currency', type: 'select', options: props.currencies, valueKey: 'id', labelKey: 'code' },
            { key: 'payment_term_id', label: 'Payment terms', type: 'select', options: props.paymentTerms, valueKey: 'id', labelKey: 'name' },
            { key: 'credit_limit', label: 'Credit limit', type: 'number', step: '0.0001', default: 0, rule: 'BR-46', hint: 'Zero means no limit set, not no credit.' },
            { key: 'min_order_value', label: 'Minimum order value', type: 'number', step: '0.0001', default: 0, rule: 'BR-21' },
            { key: 'under_tolerance_pct', label: 'Under tolerance %', type: 'number', step: '0.01', default: 5, rule: 'BR-44' },
            { key: 'over_tolerance_pct', label: 'Over tolerance %', type: 'number', step: '0.01', default: 5, rule: 'BR-44' },
            { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
        ],
    },
]);
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${customer.code ?? ''}` : 'New customer'" />

        <template #title>{{ isEdit ? `Edit ${customer.name ?? customer.code}` : 'New customer' }}</template>

        <ResourceForm
            :sections="sections"
            :initial="customer ?? {}"
            :action="isEdit ? `/customers/${customer.id}` : '/customers'"
            :method="isEdit ? 'put' : 'post'"
            :submit-label="isEdit ? 'Save changes' : 'Create customer'"
            cancel-href="/customers"
        />
    </AppLayout>
</template>
