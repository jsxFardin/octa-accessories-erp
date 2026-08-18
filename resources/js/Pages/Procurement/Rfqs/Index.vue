<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    rfqs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'pr_number', label: 'Requisition' },
    { key: 'issued_on', label: 'Issued', sort: true },
    { key: 'respond_by', label: 'Respond by', sort: true },
    { key: 'quotations_count', label: 'Quotes', align: 'right' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="RFQs" />

        <template #title>Requests for quotation</template>
        <template #subtitle>Issue one RFQ to several suppliers, compare the replies, then raise the PO from the winner.</template>

        <template #actions>
            <Button v-if="can('rfq.create')" variant="primary" href="/rfqs/create">New RFQ</Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[{ key: 'status', label: 'Status', options: ['draft','issued','closed','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]"
                placeholder="Search RFQ number…"
            />

            <DataTable :columns="columns" :rows="rfqs" row-key="id" :row-href="(row) => `/rfqs/${row.id}`" empty="No RFQs.">
                <template #cell:number="{ row, value }">
                    <Link :href="`/rfqs/${row.id}`" class="font-medium text-brand-700">{{ value ?? '(draft)' }}</Link>
                </template>
                <template #cell:issued_on="{ value }">{{ date(value) }}</template>
                <template #cell:respond_by="{ value }">{{ value ? date(value) : '—' }}</template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="rfq"
                        title="No RFQs yet"
                        description="Raise an RFQ from an approved requisition, issue it, then record the supplier quotations that come back."
                        :action-label="can('rfq.create') ? 'New RFQ' : null"
                        action-href="/rfqs/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
