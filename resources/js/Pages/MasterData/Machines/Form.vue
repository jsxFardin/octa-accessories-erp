<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ResourceForm from '@/Components/Ui/ResourceForm.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ machine: Object, groups: Array, units: Array, departments: Array });

const isEdit = computed(() => Boolean(props.machine));

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'code', label: 'Code', required: true },
            { key: 'name', label: 'Name', required: true },
            { key: 'machine_group_id', label: 'Group', type: 'select', options: groups, valueKey: 'id', labelKey: 'name', required: true },
            { key: 'factory_unit_id', label: 'Factory unit', type: 'select', options: units, valueKey: 'id', labelKey: 'name', required: true },
            { key: 'department_id', label: 'Department', type: 'select', options: departments, valueKey: 'id', labelKey: 'name' },
            { key: 'make', label: 'Make' },
            { key: 'model', label: 'Model' },
            { key: 'serial_no', label: 'Serial no' },
            { key: 'commissioned_on', label: 'Commissioned', type: 'date' },
        ],
    },
    {
        title: 'Capability and cost',
        rule: 'BR-16 · BR-18 · BR-27',
        fields: [
            { key: 'web_width_mm', label: 'Web width mm', type: 'number', step: '0.01', hint: 'Lets the planner reject an impossible assignment' },
            { key: 'max_colours', label: 'Max colours', type: 'number' },
            { key: 'std_rate_per_hour', label: 'Std rate / hour', type: 'number', step: '0.000001', rule: 'BR-16' },
            { key: 'hourly_rate', label: 'Cost / hour', type: 'number', step: '0.0001', default: 0, rule: 'BR-16' },
            { key: 'kw_rating', label: 'kW rating', type: 'number', step: '0.001', rule: 'BR-18' },
            { key: 'efficiency_pct', label: 'Efficiency %', type: 'number', step: '0.01', default: 85, rule: 'BR-27', hint: 'The planning rate is the nameplate rate times this.' },
            { key: 'status', label: 'Status', type: 'select', default: 'available', options: [
                { value: 'available', label: 'Available' },
                { value: 'running', label: 'Running' },
                { value: 'maintenance', label: 'Maintenance' },
                { value: 'breakdown', label: 'Breakdown' },
                { value: 'retired', label: 'Retired' },
            ] },
            { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
        ],
    },
]);
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${machine.code ?? ''}` : 'New machine'" />

        <template #title>{{ isEdit ? `Edit ${machine.name ?? machine.code}` : 'New machine' }}</template>

        <ResourceForm
            :sections="sections"
            :initial="machine ?? {}"
            :action="isEdit ? `/machines/${machine.id}` : '/machines'"
            :method="isEdit ? 'put' : 'post'"
            :submit-label="isEdit ? 'Save changes' : 'Create machine'"
            cancel-href="/machines"
        />
    </AppLayout>
</template>
