<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import ExportDialog from '@/Components/Ui/ExportDialog.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, pct, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { useConfirm } from '@/composables/useConfirm';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const { confirm } = useConfirm();

const props = defineProps({
    routings: Object,
    filters: Object,
    families: { type: Array, default: () => [] },
});

async function remove(row) {
    if (!await confirm({
        title: `Retire ${row.code ?? row.name}?`,
        message: 'History is kept; it simply stops being offered.',
        confirmLabel: 'Retire',
    })) return;

    router.delete(`/routings/${row.id}`, { preserveScroll: true });
}

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/routings/${row.id}`) },
        { label: 'Edit', hidden: !can('routing.update'), onSelect: () => router.visit(`/routings/${row.id}/edit`) },
        {
            label: 'Retire',
            tone: 'danger',
            hidden: !can('routing.delete'),
            onSelect: () => remove(row),
        },
    ];
}

const columns = [
    { key: 'code', label: 'Code', sort: true },
    { key: 'name', label: 'Name', sort: true },
    { key: 'family', label: 'Family' },
    { key: 'product_type', label: 'Label type', sort: true },
    { key: 'operations_count', label: 'Operations', align: 'center' },
    { key: 'wastage', label: 'Total wastage', align: 'right' },
    { key: 'max_lot_size', label: 'Max lot', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head title="Routings" />

        <template #title>Routings</template>
        <template #subtitle>One default routing per family — its standard process — plus the label types' own</template>

        <template #actions>
            <ExportDialog v-if="can('routing.export')" resource="routings" />
            <Button v-if="can('routing.create')" variant="primary" href="/routings/create">New routing</Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'family', label: 'Family', options: families },
                    { key: 'product_type', label: 'Label type', options: ['woven','flexo','screen','heat_transfer','offset_tag','thermal'].map((s) => ({ value: s, label: titleCase(s) })) },
                ]"
                placeholder="Search routing code or name…"
            />

            <DataTable
                :columns="columns"
                :rows="routings"
                row-key="id" :actions="rowActions" :row-href="(row) => `/routings/${row.id}`"
                empty="No routings defined."
            >
                <template #cell:code="{ row, value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:family="{ row }">
                    <span v-if="row.family">{{ row.family.code }} · {{ row.family.name }}</span>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:product_type="{ value }">{{ value ? titleCase(value) : '—' }}</template>
                <template #cell:operations_count="{ row, value }">{{ row.operations?.length ?? 0 }}</template>
                <template #cell:wastage="{ row, value }"><span class="tnum">{{ pct((row.operations ?? []).filter((o) => o.consumes_web).reduce((sum, o) => sum + Number(o.wastage_pct), 0), 2) }}</span></template>
                <template #cell:max_lot_size="{ row, value }">{{ value ? pcs(value) : "—" }}</template>
                <template #empty>
                    <EmptyState
                        icon="routing"
                        title="No routings yet"
                        description="A routing is the ordered operations a product type passes through, carrying the wastage defaults."
                        :action-label="can('routing.create') ? 'New routing' : null"
                        action-href="/routings/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
