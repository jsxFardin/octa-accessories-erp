<script setup>
import { computed } from 'vue';
import StageStrip from '@/Components/Ui/StageStrip.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import ExportDialog from '@/Components/Ui/ExportDialog.vue';
import ImportDialog from '@/Components/Ui/ImportDialog.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, number, pcs, pct, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { useConfirm } from '@/composables/useConfirm';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const { confirm } = useConfirm();

const props = defineProps({
    suppliers: Object,
    filters: Object,
    countries: Array,
    /** `{ approved, not_approved, inactive }`, whatever the filters say. */
    counts: { type: Object, default: () => ({}) },
});

/*
 * Two yes/no columns made three kinds of supplier: ones an order can go to, ones waiting to be
 * approved, and ones switched off. The strip counts them as that, and each tile is the filter.
 */
const KINDS = {
    approved: { label: 'Can be ordered from', query: { active: '1', approved: '1' } },
    not_approved: { label: 'Waiting for approval', query: { active: '1', approved: '0' } },
    inactive: { label: 'Inactive', query: { active: '0' } },
};

const activeKind = computed(() => Object.keys(KINDS).find((key) => Object.entries({ active: '', approved: '', ...KINDS[key].query })
    .every(([param, value]) => String(props.filters?.[param] ?? '') === value)) ?? null);

const stages = computed(() => Object.entries(KINDS).map(([key, kind]) => ({
    key,
    label: kind.label,
    count: props.counts[key] ?? 0,
    active: activeKind.value === key,
    tone: key === 'not_approved' ? 'warning' : 'neutral',
})));

function select(key) {
    const { active, approved, page, ...rest } = props.filters ?? {};
    const next = activeKind.value === key ? rest : { ...rest, ...KINDS[key].query };

    router.get('/suppliers', Object.fromEntries(Object.entries(next).filter(([, value]) => value !== '' && value !== null && value !== undefined)), { preserveState: true, preserveScroll: true });
}

async function remove(row) {
    if (!await confirm({
        title: `Archive ${row.code ?? row.name}?`,
        message: 'History is kept; it simply stops being offered.',
        confirmLabel: 'Archive',
    })) return;

    router.delete(`/suppliers/${row.id}`, { preserveScroll: true });
}

/** Built per row so the menu never offers what this user may not do, or the record will not allow. */
function rowActions(row) {
    return [
        { label: 'Open', onSelect: () => router.visit(`/suppliers/${row.id}`) },
        { label: 'Edit', hidden: !can('supplier.update'), onSelect: () => router.visit(`/suppliers/${row.id}/edit`) },
        {
            label: 'Archive',
            tone: 'danger',
            hidden: !can('supplier.delete'),
            onSelect: () => remove(row),
        },
    ];
}

const columns = [
    { key: 'name', label: 'Supplier', sort: true },
    { key: 'standing', label: 'Standing' },
    { key: 'country', label: 'Country', sort: true },
    { key: 'open_orders', label: 'On order now', align: 'right' },
    { key: 'lead_time_days', label: 'Usual lead time', align: 'right' },
    { key: 'rating', label: 'Rating', align: 'right', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Suppliers" />

        <template #title>Suppliers</template>
        <template #subtitle>Who the factory buys from, and who an order can be sent to today</template>

        <template #actions>
            <ImportDialog v-if="can('supplier.import')" resource="suppliers" label="Suppliers" />
            <ExportDialog v-if="can('supplier.export')" resource="suppliers" />
            <Button v-if="can('supplier.create')" variant="primary" href="/suppliers/create">New supplier</Button>
        </template>

        <StageStrip :stages="stages" label="Suppliers by standing" @select="select" />

        <Card :padded="false">
            <FilterBar :only="['suppliers', 'filters']" :filters="filters" :fields="[{ key: 'approved', label: 'Approval', options: [{ value: '1', label: 'Approved' }, { value: '0', label: 'Not approved' }] }, { key: 'active', label: 'Active', options: [{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] }, { key: 'country', label: 'Country', options: (countries ?? []).map((country) => ({ value: country, label: country })) }]" placeholder="Search code, name or country…" />

            <DataTable
                :only="['suppliers', 'filters']"
                :columns="columns"
                :rows="suppliers"
                row-key="id" :actions="rowActions" :row-href="(row) => `/suppliers/${row.id}`"
                empty="No suppliers match these filters."
            >
                <template #cell:name="{ row, value }">
                    <Link :href="`/suppliers/${row.id}`" class="doc-link-quiet">{{ value }}</Link>
                    <span class="block text-xs text-ink-500">{{ row.code }}</span>
                </template>
                <!-- One answer, not two columns to combine: can an order go to them or not. -->
                <template #cell:standing="{ row }">
                    <Badge v-if="!row.is_active" tone="neutral" label="Inactive" />
                    <Badge v-else-if="row.is_approved" tone="success" label="Approved" />
                    <Badge v-else tone="warning" label="Waiting for approval" />
                </template>
                <template #cell:country="{ value }"><span :class="value ? '' : 'text-ink-400'">{{ value || '—' }}</span></template>
                <template #cell:open_orders="{ row, value }">
                    <Link v-if="value > 0" :href="`/purchase-orders?supplier=${row.id}`" class="doc-link-quiet tnum">{{ value }} {{ value === 1 ? 'order' : 'orders' }}</Link>
                    <span v-else class="text-ink-400">None</span>
                </template>
                <template #cell:lead_time_days="{ value }"><span class="tnum">{{ value }} days</span></template>
                <template #cell:rating="{ value }"><span :class="value !== null ? 'tnum' : 'text-ink-400'">{{ value !== null ? `${number(value, 1)} of 5` : 'Not rated' }}</span></template>
                <template #empty>
                    <EmptyState
                        icon="supplier"
                        title="No suppliers yet"
                        description="Only an approved supplier can be sent a purchase order, and only their lots can carry a certification claim."
                        :action-label="can('supplier.create') ? 'New supplier' : null"
                        action-href="/suppliers/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
