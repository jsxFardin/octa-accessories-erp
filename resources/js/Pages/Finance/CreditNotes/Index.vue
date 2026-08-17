<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ credit_notes: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'customer', label: 'Customer' },
    { key: 'invoice', label: 'Invoice' },
    { key: 'note_date', label: 'Date', sort: true },
    { key: 'reason', label: 'Reason' },
    { key: 'amount', label: 'Amount', align: 'right', sort: true },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Credit notes" />

        <template #title>Credit notes</template>
        <template #subtitle>Applied credits reduce an invoice's outstanding: total = received + credited + outstanding</template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[
                { key: 'status', label: 'Status', options: ['draft','approved','applied','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) },
                { key: 'reason', label: 'Reason', options: ['return','quality_claim','short_delivery','rate_difference','discount','other'].map((s) => ({ value: s, label: titleCase(s) })) },
            ]" placeholder="Search credit note number…" />

            <DataTable :columns="columns" :rows="credit_notes" row-key="id" empty="No credit notes.">
                <template #cell:number="{ row, value }"><Link :href="`/credit-notes/${row.id}`" class="font-medium text-brand-700">{{ value ?? '(draft)' }}</Link></template>
                <template #cell:note_date="{ value }">{{ date(value) }}</template>
                <template #cell:reason="{ value }">{{ titleCase(value) }}</template>
                <template #cell:amount="{ value }">{{ money(value) }}</template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="money"
                        title="No credit notes"
                        description="A challan return against an issued invoice drafts one automatically; claims and rate differences are raised by hand from the invoice."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
