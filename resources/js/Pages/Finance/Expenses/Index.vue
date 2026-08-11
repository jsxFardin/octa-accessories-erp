<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import ExportDialog from '@/Components/Ui/ExportDialog.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, titleCase } from '@/plugins/formatting';
import { useConfirm } from '@/composables/useConfirm';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    expenses: Object,
    filters: Object,
    categories: { type: Array, default: () => [] },
    methods: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
});

const { confirm } = useConfirm();

const busy = ref(null);

/** What this row can become — the same ladder the controller enforces. */
const NEXT = {
    draft: ['pending_approval', 'cancelled'],
    pending_approval: ['approved', 'draft', 'cancelled'],
    approved: ['paid', 'cancelled'],
};

async function move(row, status) {
    if (status === 'cancelled' && !await confirm({
        title: `Cancel ${row.number}?`,
        message: 'The record stays; it stops counting towards spend.',
        confirmLabel: 'Cancel expense',
    })) return;

    busy.value = row.id;

    router.post(`/expenses/${row.id}/transition`, { status }, {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
}

function rowActions(row) {
    const actions = (NEXT[row.status] ?? []).map((status) => ({
        label: status === 'pending_approval' ? 'Submit for approval' : titleCase(status),
        tone: status === 'cancelled' ? 'danger' : undefined,
        // Approve and pay are separate rights, and neither belongs to the person who raised it.
        hidden: (status === 'approved' && !can('expense.approve')) || (status === 'paid' && !can('expense.pay')),
        onSelect: () => move(row, status),
    }));

    if (['draft', 'pending_approval'].includes(row.status) && can('expense.update')) {
        actions.unshift({ label: 'Edit', onSelect: () => router.visit(`/expenses/${row.id}/edit`) });
    }

    return actions;
}

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'expense_date', label: 'Date', sort: true },
    { key: 'category', label: 'Category' },
    { key: 'payee', label: 'Payee' },
    { key: 'method', label: 'Method' },
    { key: 'total', label: 'Total', align: 'right', sort: true },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Expenses" />

        <template #title>Expenses</template>
        <template #subtitle>The money that leaves outside the purchase-order path — and who signed for it</template>

        <template #actions>
            <ExportDialog v-if="can('expense.export')" resource="expenses" />
            <Button v-if="can('expense.create')" variant="primary" href="/expenses/create">New expense</Button>
        </template>

        <div class="space-y-4">
            <!--
                Two figures and the top categories, under the same filters as the list. An
                expense screen that cannot answer "what have we spent" is a filing cabinet.
            -->
            <Card v-if="totals.committed !== undefined" title="Under these filters">
                <div class="flex flex-wrap items-start gap-8">
                    <div>
                        <p class="text-xs tracking-wider text-ink-500 uppercase">Committed</p>
                        <p class="tnum text-xl font-semibold text-ink-900">{{ money(totals.committed) }}</p>
                    </div>
                    <div>
                        <p class="text-xs tracking-wider text-ink-500 uppercase">Paid</p>
                        <p class="tnum text-xl font-semibold text-ink-900">{{ money(totals.paid) }}</p>
                    </div>

                    <ul v-if="totals.byCategory?.length" class="flex flex-1 flex-wrap gap-x-6 gap-y-1 pt-1">
                        <li v-for="row in totals.byCategory" :key="row.category" class="text-xs">
                            <span class="text-ink-500">{{ row.category }}</span>
                            <span class="tnum ml-1.5 font-medium text-ink-800">{{ money(row.amount) }}</span>
                        </li>
                    </ul>
                </div>
            </Card>

            <Card :padded="false">
                <FilterBar
                    :filters="filters"
                    :fields="[
                        { key: 'status', label: 'Status', options: statuses.map((s) => ({ value: s, label: titleCase(s) })) },
                        { key: 'category', label: 'Category', options: categories.map((c) => ({ value: String(c.id), label: c.name })) },
                        { key: 'method', label: 'Method', options: methods.map((m) => ({ value: m, label: titleCase(m) })) },
                    ]"
                    placeholder="Search number, payee or reference…"
                />

                <DataTable
                    :columns="columns"
                    :rows="expenses"
                    row-key="id"
                    :actions="rowActions"
                    empty="No expenses match these filters."
                >
                    <template #cell:number="{ value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                    <template #cell:expense_date="{ value }">{{ date(value) }}</template>
                    <template #cell:payee="{ row, value }">
                        {{ value }}
                        <span v-if="row.description" class="block text-xs text-ink-500">{{ row.description }}</span>
                    </template>
                    <template #cell:method="{ value }">{{ titleCase(value) }}</template>
                    <template #cell:total="{ value }">{{ money(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>

                    <template #empty>
                        <EmptyState
                            icon="receipt"
                            title="No expenses yet"
                            description="Generator fuel, courier, port charges, an audit fee — none of it goes through a purchase order, all of it is real, and a system that only knows about orders reports a factory that costs less to run than it does."
                            :action-label="can('expense.create') ? 'New expense' : null"
                            action-href="/expenses/create"
                            :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                            @clear-filters="router.get(window.location.pathname)"
                        />
                    </template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
