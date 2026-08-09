<script setup>
import { Head } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    tests: { type: Array, default: () => [] },
    reports: { type: Object, required: true },
    customerRequirements: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});
</script>

<template>
    <AppLayout>
        <Head title="Laboratory" />

        <template #title>Laboratory</template>
        <template #subtitle>BR-32 — the nine tests the factory advertises, with their methods and thresholds</template>

        <div class="space-y-4">
            <Card title="Test catalogue" rule="BR-32" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'code', label: 'Code' },
                        { key: 'name', label: 'Test' },
                        { key: 'method', label: 'Method' },
                        { key: 'scale', label: 'Scale' },
                        { key: 'default_pass_value', label: 'House threshold', align: 'right' },
                        { key: 'unit', label: 'Unit' },
                    ]"
                    :rows="tests"
                    row-key="id"
                    empty="No tests seeded."
                    dense
                >
                    <template #cell:code="{ value }"><span class="font-medium text-slate-900">{{ value }}</span></template>
                    <template #cell:scale="{ value }">{{ titleCase(value) }}</template>
                </DataTable>
            </Card>

            <Card
                title="Customer thresholds"
                rule="BR-32"
                subtitle="A brand may impose a stricter limit than the house default without forking the catalogue"
                :padded="false"
            >
                <DataTable
                    :columns="[
                        { key: 'customer', label: 'Customer' },
                        { key: 'product_code', label: 'Product' },
                        { key: 'test_name', label: 'Test' },
                        { key: 'default_pass_value', label: 'House', align: 'right' },
                        { key: 'pass_value', label: 'Required', align: 'right' },
                        { key: 'is_mandatory', label: 'Mandatory' },
                    ]"
                    :rows="customerRequirements"
                    row-key="id"
                    empty="No customer-specific thresholds. The house defaults apply everywhere."
                    dense
                >
                    <template #cell:product_code="{ value }">{{ value ?? 'all products' }}</template>
                    <template #cell:is_mandatory="{ value }">
                        <Badge :tone="value ? 'warning' : 'neutral'" :label="value ? 'Mandatory' : 'Optional'" />
                    </template>
                </DataTable>
            </Card>

            <Card title="Test reports" rule="QC3" subtitle="Immutable once issued — reprinting reproduces the original values" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'number', label: 'Number' },
                        { key: 'issued_on', label: 'Issued' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="reports"
                    row-key="id"
                    empty="No test reports issued."
                    dense
                >
                    <template #cell:issued_on="{ value }">{{ value ? date(value) : '—' }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
