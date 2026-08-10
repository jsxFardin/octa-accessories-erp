<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, ratePerM } from '@/plugins/formatting';
import { useConfirm } from '@/composables/useConfirm';
import { can } from '@/plugins/permissions';

defineProps({ lists: Object, filters: Object });

const { confirm } = useConfirm();

function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/price-lists/${row.id}`) },
        { label: 'Edit', hidden: !can('price_list.update'), onSelect: () => router.visit(`/price-lists/${row.id}/edit`) },
        {
            label: 'Deactivate',
            tone: 'danger',
            hidden: !can('price_list.delete') || !row.is_active,
            onSelect: async () => {
                if (await confirm({
                    title: `Deactivate ${row.code}?`,
                    message: 'Quotations already priced from it keep their rates.',
                    confirmLabel: 'Deactivate',
                })) {
                    router.delete(`/price-lists/${row.id}`, { preserveScroll: true });
                }
            },
        },
    ];
}

const columns = [
    { key: 'code', label: 'Code', sort: true },
    { key: 'name', label: 'Name' },
    { key: 'customer', label: 'Customer' },
    { key: 'currency', label: 'Currency' },
    { key: 'valid_from', label: 'From' },
    { key: 'valid_to', label: 'To' },
    { key: 'lines_count', label: 'Lines', align: 'center' },
    { key: 'is_active', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Price lists" />

        <template #title>Price lists</template>
        <template #subtitle>Agreed rates by quantity break — a quotation reads these before it computes a cost sheet</template>

        <template #actions>
            <Button v-if="can('price_list.create')" variant="primary" href="/price-lists/create">New price list</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" placeholder="Search code or name…" />

            <DataTable
                :columns="columns"
                :rows="lists"
                row-key="id"
                :row-href="(row) => `/price-lists/${row.id}`"
                :actions="rowActions"
            >
                <template #cell:code="{ value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:valid_from="{ value }">{{ date(value) }}</template>
                <template #cell:valid_to="{ value }">{{ value ? date(value) : "open-ended" }}</template>
                <template #cell:is_active="{ value }">
                    <Badge :tone="value ? 'success' : 'neutral'" :label="value ? 'Active' : 'Inactive'" />
                </template>

                <template #empty>
                    <EmptyState
                        icon="card"
                        title="No price lists yet"
                        description="A price list fixes the rate for a customer's products by quantity break, so a repeat order is not re-costed from scratch each time."
                        :action-label="can('price_list.create') ? 'New price list' : null"
                        action-href="/price-lists/create"
                        :filtered="Boolean(filters.q)"
                        @clear-filters="router.get('/price-lists')"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
