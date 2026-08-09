<script setup>
import { Head } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ sequences: { type: Array, default: () => [] } });

const columns = [
    { key: 'document_type', label: 'Document' },
    { key: 'series_key', label: 'Series' },
    { key: 'prefix', label: 'Prefix' },
    { key: 'next_number', label: 'Next', align: 'right' },
    { key: 'padding', label: 'Padding', align: 'right' },
    { key: 'next_formatted', label: 'Next number' },
];
</script>

<template>
    <AppLayout>
        <Head title="Number sequences" />

        <template #title>Number sequences</template>
        <template #subtitle>
            BR-34 — allocated under a row lock inside the transaction that inserts the document.
            Read-only on purpose: editing a counter by hand is how a VAT series acquires a duplicate.
        </template>

        <Card :padded="false">
            <DataTable :columns="columns" :rows="sequences" row-key="id" empty="No sequences seeded.">
                <template #cell:document_type="{ value }">{{ titleCase(value) }}</template>
                <template #cell:next_formatted="{ value }">
                    <span class="font-mono text-xs font-medium text-slate-900">{{ value }}</span>
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
