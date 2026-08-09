<script setup>
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import { qty } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    schemes: { type: Array, default: () => [] },
});

const columns = [
    { key: 'scheme', label: 'Scheme' },
    { key: 'period', label: 'Period' },
    { key: 'certified_input_qty', label: 'Certified in', align: 'right' },
    { key: 'certified_output_qty', label: 'Certified out', align: 'right' },
    { key: 'conversion_factor', label: 'Conversion', align: 'right' },
    { key: 'max_conversion_factor', label: 'Max', align: 'right' },
    { key: 'flagged', label: 'Audit' },
];
</script>

<template>
    <AppLayout>
        <Head title="Chain of custody reconciliation" />

        <template #title>Chain of custody reconciliation</template>
        <template #subtitle>
            Certified input against certified output, per scheme per period — the exact figure a GRS or FSC auditor asks for (BR-42)
        </template>

        <template #actions>
            <div class="w-36">
                <SelectInput
                    :model-value="filters.scheme ?? ''"
                    placeholder="All schemes"
                    :options="schemes.map((scheme) => ({ value: scheme, label: scheme.replace('_', ' ') }))"
                    @update:model-value="router.get('/compliance/reconciliation', { ...filters, scheme: $event || undefined }, { preserveState: true, replace: true })"
                />
            </div>
        </template>

        <Card :padded="false">
            <DataTable
                :columns="columns"
                :rows="rows"
                row-key="period"
                empty="No chain-of-custody transactions recorded yet. Certified input enters the system on a GRN line."
            >
                <template #cell:scheme="{ value }">
                    <span class="font-medium text-ink-900">{{ value.replace('_', ' ') }}</span>
                </template>
                <template #cell:certified_input_qty="{ value }">{{ qty(value) }}</template>
                <template #cell:certified_output_qty="{ value }">{{ qty(value) }}</template>
                <template #cell:conversion_factor="{ value }">{{ Number(value).toFixed(4) }}</template>
                <template #cell:flagged="{ value }">
                    <!-- Flagged means more certified goods left than came in: the condition an auditor tests -->
                    <Badge v-if="value" tone="danger" label="Exceeds input" />
                    <Badge v-else tone="success" label="Within input" />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
