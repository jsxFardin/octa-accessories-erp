<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ResourceForm from '@/Components/Ui/ResourceForm.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ supplier: Object });

const isEdit = computed(() => Boolean(props.supplier));

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'code', label: 'Code', required: true },
            { key: 'name', label: 'Name', required: true },
            { key: 'country', label: 'Country', hint: 'Yarn: UK, Turkey, China, Hong Kong, India' },
            { key: 'bin_no', label: 'BIN' },
            { key: 'tin_no', label: 'TIN' },
        ],
    },
    {
        title: 'Terms',
        rule: 'BR-26',
        fields: [
            { key: 'lead_time_days', label: 'Lead time (days)', type: 'number', rule: 'BR-26', hint: 'Default; a supplier-item row overrides it.' },
            { key: 'rating', label: 'Rating', type: 'number', step: '0.1' },
            { key: 'is_approved', label: 'Approved', type: 'checkbox', checkboxLabel: 'A PO may only be submitted to an approved supplier' },
            { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
        ],
    },
]);
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${supplier.code ?? ''}` : 'New supplier'" />

        <template #title>{{ isEdit ? `Edit ${supplier.name ?? supplier.code}` : 'New supplier' }}</template>

        <ResourceForm
            :sections="sections"
            :initial="supplier ?? {}"
            :action="isEdit ? `/suppliers/${supplier.id}` : '/suppliers'"
            :method="isEdit ? 'put' : 'post'"
            :submit-label="isEdit ? 'Save changes' : 'Create supplier'"
            cancel-href="/suppliers"
        />
    </AppLayout>
</template>
