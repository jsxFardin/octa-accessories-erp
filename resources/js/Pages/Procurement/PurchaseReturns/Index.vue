<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ returns: Object, filters: Object, suppliers: { type: Array, default: () => [] } });

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'supplier', label: 'Supplier' },
    { key: 'grn', label: 'From receipt' },
    { key: 'returned_on', label: 'Returned', sort: true },
    { key: 'lines_count', label: 'Lines', align: 'right' },
    { key: 'value', label: 'Value', align: 'right' },
    { key: 'debit_note_no', label: 'Debit note' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Supplier returns" />

        <template #title>Supplier returns</template>
        <template #subtitle>Goods sent back to a supplier out of a posted receipt. Posting takes them out of the lot they came in on.</template>

        <template #actions>
            <Button v-if="can('purchase_return.create')" size="sm" variant="primary" href="/purchase-returns/create">New return</Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :only="['returns', 'filters']"
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: ['draft', 'posted', 'cancelled'].map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'supplier', label: 'Supplier', options: suppliers.map((s) => ({ value: s.id, label: s.name, code: s.code })) },
                ]"
                placeholder="Search number, reason or debit note…"
            />
            <DataTable :only="['returns', 'filters']" :columns="columns" :rows="returns" row-key="id" :row-href="(row) => `/purchase-returns/${row.id}`" empty="No supplier returns match these filters.">
                <template #cell:number="{ value }"><span class="font-medium text-ink-900">{{ value ?? '(draft)' }}</span></template>
                <template #cell:grn="{ value }">
                    <Link v-if="value" :href="`/grns/${value.id}`" class="doc-link-quiet" @click.stop>{{ value.number }}</Link>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:returned_on="{ value }">{{ date(value) }}</template>
                <template #cell:lines_count="{ value }"><span class="tnum">{{ pcs(value) }}</span></template>
                <template #cell:value="{ value }"><span class="tnum">{{ money(value) }}</span></template>
                <template #cell:debit_note_no="{ value, row }">
                    <span v-if="value" class="font-mono text-xs">{{ value }}</span>
                    <span v-else-if="row.status === 'posted'" class="text-xs text-amber-700">awaited</span>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="sales-return"
                        title="No supplier returns yet"
                        description="Short, damaged or wrong goods go back to the supplier from the receipt they arrived on; the debit note follows."
                        :action-label="can('purchase_return.create') ? 'New return' : null"
                        action-href="/purchase-returns/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
