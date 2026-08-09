<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ResourceForm from '@/Components/Ui/ResourceForm.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ product: Object, preselectedCustomer: Number, customers: Array, brands: Array, routings: Array, productTypes: Array, cutTypes: Array });

const isEdit = computed(() => Boolean(props.product));

const sections = computed(() => [
    {
        title: 'Commercial identity',
        rule: 'P1',
        fields: [
            { key: 'customer_id', label: 'Customer', type: 'select', options: customers, valueKey: 'id', labelKey: 'name', required: true, rule: 'P1', hint: 'A product belongs to exactly one customer, permanently.' },
            { key: 'brand_id', label: 'Brand', type: 'select', options: brands, valueKey: 'id', labelKey: 'name' },
            { key: 'code', label: 'Code', required: true },
            { key: 'name', label: 'Name', required: true },
            { key: 'customer_style_ref', label: 'Customer style ref' },
        ],
    },
    {
        title: 'Manufacturing',
        fields: [
            { key: 'product_type', label: 'Product type', type: 'select', options: productTypes, required: true },
            { key: 'routing_id', label: 'Routing', type: 'select', options: routings, valueKey: 'id', labelKey: 'name' },
            { key: 'is_running_programme', label: 'Running programme', type: 'checkbox', rule: 'BR-15', checkboxLabel: 'Amortise tooling over the annual forecast' },
            { key: 'annual_forecast_qty', label: 'Annual forecast qty', type: 'number', step: '0.000001', rule: 'BR-15' },
            { key: 'status', label: 'Status', type: 'select', default: 'development', options: [
                { value: 'development', label: 'Development' },
                { value: 'active', label: 'Active' },
                { value: 'on_hold', label: 'On hold' },
                { value: 'discontinued', label: 'Discontinued' },
            ] },
            { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
        ],
    },
]);
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${product.code ?? ''}` : 'New product'" />

        <template #title>{{ isEdit ? `Edit ${product.name ?? product.code}` : 'New product' }}</template>

        <ResourceForm
            :sections="sections"
            :initial="product ?? {}"
            :action="isEdit ? `/products/${product.id}` : '/products'"
            :method="isEdit ? 'put' : 'post'"
            :submit-label="isEdit ? 'Save changes' : 'Create product'"
            cancel-href="/products"
        />
    </AppLayout>
</template>
