<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ inquiries: Object, filters: Object, customers: Array });

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/inquiries/${row.id}`) },
        { label: 'Edit', hidden: !can('inquiry.update') || !(row.status === 'draft'), onSelect: () => router.visit(`/inquiries/${row.id}/edit`) },
    ];
}

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'customer', label: 'Customer' },
    { key: 'inquiry_date', label: 'Received', sort: true },
    { key: 'required_by', label: 'Required by', sort: true },
    { key: 'lines_count', label: 'Lines', align: 'center' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Inquiries" />

        <template #title>Inquiries</template>
        <template #subtitle>The front of the funnel — numbered on submit, never on form open (BR-34)</template>

        <template #actions>
            <Button v-if="can('inquiry.create')" variant="primary" href="/inquiries/create">New inquiry</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','open','quoted','won','lost','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }, { key: 'customer', label: 'Customer', options: customers.map((c) => ({ value: c.id, label: c.name })) }]" placeholder="Search inquiry number…" />

            <DataTable
                :columns="columns"
                :rows="inquiries"
                row-key="id" :actions="rowActions" :row-href="(row) => `/inquiries/${row.id}`"
                empty="No inquiries match these filters."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:inquiry_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:required_by="{ row, value }">{{ date(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="inbox"
                        title="No inquiries yet"
                        description="An inquiry is the front of the funnel: what a customer asked for, before it has been costed."
                        :action-label="can('inquiry.create') ? 'New inquiry' : null"
                        action-href="/inquiries/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
