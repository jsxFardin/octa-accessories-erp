<script setup>
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import ExportDialog from '@/Components/Ui/ExportDialog.vue';
import ImportDialog from '@/Components/Ui/ImportDialog.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, titleCase } from '@/plugins/formatting';
import { useConfirm } from '@/composables/useConfirm';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const { confirm } = useConfirm();

const props = defineProps({ customers: Object, filters: Object, kinds: { type: Array, default: () => [] } });

async function remove(row) {
    if (!await confirm({
        title: `Archive ${row.code ?? row.name}?`,
        message: 'History is kept; it simply stops being offered.',
        confirmLabel: 'Archive',
    })) return;

    router.delete(`/customers/${row.id}`, { preserveScroll: true });
}

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/customers/${row.id}`) },
        { label: 'New inquiry', hidden: !can('inquiry.create') || !row.is_active, onSelect: () => router.visit(`/inquiries/create?customer=${row.id}`) },
        { label: 'Edit', hidden: !can('customer.update'), onSelect: () => router.visit(`/customers/${row.id}/edit`) },
        {
            label: 'Archive',
            tone: 'danger',
            hidden: !can('customer.delete'),
            onSelect: () => remove(row),
        },
    ];
}

/*
 * What a salesperson scans for: who has work on order, who owes money, who has gone quiet,
 * who is waiting on a quote. The credit limit and tolerances are settings and live on the
 * page; eleven identical rows of "BDT 2,000,000 · 5.0000 · 5.0000" said nothing.
 */
const columns = [
    { key: 'code', label: 'Code', sort: true },
    { key: 'name', label: 'Name', sort: true },
    { key: 'kind', label: 'Kind' },
    { key: 'open_order_value', label: 'On order', align: 'right', sort: true },
    { key: 'outstanding', label: 'Outstanding', align: 'right', sort: true },
    { key: 'last_order_on', label: 'Last order', sort: true },
    { key: 'quotations_out', label: 'Quotes out', align: 'right' },
    { key: 'is_active', label: 'Active' },
];

const kindLabel = (value) => props.kinds.find((k) => k.value === value)?.label ?? titleCase(value);
</script>

<template>
    <AppLayout>
        <Head title="Customers" />

        <template #title>Customers</template>
        <template #subtitle>Who you sell to: what they have on order, what they owe, and when they last ordered</template>

        <template #actions>
            <ImportDialog v-if="can('customer.import')" resource="customers" label="Customers" />
            <ExportDialog v-if="can('customer.export')" resource="customers" />
            <Button v-if="can('customer.create')" variant="primary" href="/customers/create">New customer</Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :only="['customers', 'filters']"
                :filters="filters"
                :fields="[
                    { key: 'active', label: 'Status', options: [{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] },
                    { key: 'kind', label: 'Kind', options: kinds },
                ]"
                placeholder="Search code, name, email or phone…"
            />

            <DataTable
                :only="['customers', 'filters']"
                :columns="columns"
                :rows="customers"
                row-key="id" :actions="rowActions" :row-href="(row) => `/customers/${row.id}`"
                empty="No customers match these filters."
            >
                <template #cell:code="{ value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:kind="{ value }">{{ kindLabel(value) }}</template>
                <!-- Base currency throughout: the limit is stated in it, and a list that mixed USD and BDT rows could not be compared. -->
                <template #cell:open_order_value="{ row, value }">
                    <span v-if="row.open_order_count > 0" class="tnum">
                        {{ money(value) }}
                        <span class="block text-xs text-ink-500">{{ pcs(row.open_order_count) }} {{ row.open_order_count === 1 ? 'order' : 'orders' }}</span>
                    </span>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:outstanding="{ row, value }">
                    <span v-if="Number(value) > 0" class="tnum" :class="row.overdue > 0 ? 'text-rose-700' : ''">
                        {{ money(value) }}
                        <span v-if="row.overdue > 0" class="block text-xs">{{ money(row.overdue) }} overdue</span>
                    </span>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:last_order_on="{ value }"><span :class="value ? '' : 'text-ink-400'">{{ value ? date(value) : 'Never' }}</span></template>
                <template #cell:quotations_out="{ value }"><span class="tnum" :class="value ? 'text-ink-900' : 'text-ink-400'">{{ value ? pcs(value) : '—' }}</span></template>
                <template #cell:is_active="{ value }"><Badge :tone="value ? 'success' : 'neutral'" :label="value ? 'Active' : 'Inactive'" /></template>
                <template #empty>
                    <EmptyState
                        icon="customers"
                        title="No customers yet"
                        description="A customer carries the credit limit, the delivery tolerances and the price list every order inherits."
                        :action-label="can('customer.create') ? 'New customer' : null"
                        action-href="/customers/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
