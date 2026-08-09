<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ResourceForm from '@/Components/Ui/ResourceForm.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ item: Object, categories: Array, uoms: Array, suppliers: Array });

const isEdit = computed(() => Boolean(props.item));

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'code', label: 'Code', required: true },
            { key: 'name', label: 'Name', required: true, span: 'full' },
            { key: 'item_category_id', label: 'Category', type: 'select', options: props.categories, valueKey: 'id', labelKey: 'name', required: true },
            { key: 'description', label: 'Description', type: 'textarea', span: 'full' },
        ],
    },
    {
        title: 'Units and purchasing',
        rule: 'BR-2 · BR-25',
        fields: [
            { key: 'base_uom_id', label: 'Base UoM', type: 'select', options: props.uoms, valueKey: 'id', labelKey: 'code', required: true },
            { key: 'purchase_uom_id', label: 'Purchase UoM', type: 'select', options: props.uoms, valueKey: 'id', labelKey: 'code' },
            { key: 'default_supplier_id', label: 'Default supplier', type: 'select', options: props.suppliers, valueKey: 'id', labelKey: 'name' },
            { key: 'min_order_qty', label: 'Minimum order qty', type: 'number', step: '0.000001', default: 0, rule: 'BR-25' },
            { key: 'order_multiple', label: 'Order multiple', type: 'number', step: '0.000001', default: 1, rule: 'BR-25', hint: 'Purchase quantities round up to this. Must be greater than zero.' },
            { key: 'reorder_level', label: 'Reorder level', type: 'number', step: '0.000001', default: 0 },
            { key: 'safety_days', label: 'Safety days', type: 'number', default: 0, rule: 'BR-26' },
            { key: 'std_rate', label: 'Standard rate', type: 'number', step: '0.0001', default: 0 },
        ],
    },
    {
        title: 'Technical attributes',
        rule: 'BR-9 · BR-10 · BR-37 · BR-39',
        fields: [
            { key: 'density', label: 'Density', type: 'number', step: '0.000001', hint: 'Ink and chemical volume ↔ mass' },
            { key: 'gsm', label: 'GSM', type: 'number', step: '0.001', hint: 'Paper and film' },
            { key: 'ink_lay_gsm', label: 'Ink lay g/m²', type: 'number', step: '0.001', rule: 'BR-10', hint: 'Overrides the process default' },
            { key: 'shade_code', label: 'Shade code' },
            { key: 'is_lot_tracked', label: 'Lot tracked', type: 'checkbox', default: true },
            { key: 'is_shade_critical', label: 'Shade critical', type: 'checkbox', rule: 'BR-37', checkboxLabel: 'Suggest same-shade lots first on issue' },
            { key: 'has_expiry', label: 'Has expiry', type: 'checkbox', rule: 'BR-39' },
            { key: 'shelf_life_days', label: 'Shelf life (days)', type: 'number' },
            { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
        ],
    },
]);
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${item.code ?? ''}` : 'New item'" />

        <template #title>{{ isEdit ? `Edit ${item.name ?? item.code}` : 'New item' }}</template>

        <ResourceForm
            :sections="sections"
            :initial="item ?? {}"
            :action="isEdit ? `/items/${item.id}` : '/items'"
            :method="isEdit ? 'put' : 'post'"
            :submit-label="isEdit ? 'Save changes' : 'Create item'"
            cancel-href="/items"
        />
    </AppLayout>
</template>
