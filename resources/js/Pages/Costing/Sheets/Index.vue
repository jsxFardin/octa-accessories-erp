<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import ExportDialog from '@/Components/Ui/ExportDialog.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, moneyPerThousand, pcs, pct, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    sheets: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    customers: { type: Array, default: () => [] },
    marginFloorPct: { type: Number, default: 12 },
    counts: { type: Object, default: () => ({}) },
});

/* The three stages, as the strip above the list: where the costing work sits. */
const STAGES = [
    { key: 'marketing', label: 'Marketing', hint: 'On the quotation' },
    { key: 'pre_production', label: 'Pre-production', hint: 'Locked at order confirmation' },
    { key: 'post_production', label: 'Post-production', hint: 'Actuals at job close' },
];

const stageTone = { marketing: 'info', pre_production: 'progress', post_production: 'success' };
const statusTone = { draft: 'neutral', submitted: 'warning', approved: 'success', rejected: 'danger' };

const columns = [
    { key: 'number', label: 'Sheet', sort: true },
    { key: 'stage', label: 'Stage', sort: true },
    { key: 'document', label: 'Document' },
    { key: 'product_code', label: 'Product', sort: 'product', wrap: true },
    { key: 'customer_name', label: 'Buyer', sort: 'customer' },
    { key: 'basis_qty', label: 'Qty', align: 'right', sort: true },
    { key: 'unit_cost', label: 'Cost / 1,000', align: 'right', sort: true },
    { key: 'revenue_per_unit', label: 'Price / 1,000', align: 'right', sort: true },
    { key: 'margin', label: 'Margin', align: 'right' },
    { key: 'status', label: 'Status', sort: true },
    { key: 'created_at', label: 'Costed', sort: true },
];

const filterFields = computed(() => [
    { key: 'stage', label: 'Stage', options: STAGES.map((s) => ({ value: s.key, label: s.label })) },
    { key: 'status', label: 'Status', options: ['draft', 'submitted', 'approved', 'rejected'].map((s) => ({ value: s, label: titleCase(s) })) },
    { key: 'customer', label: 'Buyer', options: props.customers.map((c) => ({ value: c.id, label: c.name, code: c.code })) },
    { key: 'below_floor', label: 'Margin', options: [{ value: '1', label: `Below the ${props.marginFloorPct}% floor` }] },
    { key: 'from', label: 'From', type: 'date' },
    { key: 'to', label: 'To', type: 'date' },
]);

function filterStage(key) {
    router.get('/costing/sheets', { ...props.filters, stage: props.filters.stage === key ? undefined : key }, { preserveState: true, replace: true, only: ['sheets', 'filters'] });
}
</script>

<template>
    <AppLayout>
        <Head title="Cost sheets" />

        <template #title>Cost sheets</template>
        <template #subtitle>
            Every costing, at every stage: the quote's estimate, the order's benchmark, the job's actuals.
        </template>

        <template #actions>
            <ExportDialog v-if="can('cost_sheet.export')" resource="cost-sheets" />
        </template>

        <nav aria-label="Cost sheets by stage" class="mb-4 grid gap-2 sm:grid-cols-3">
            <button
                v-for="stage in STAGES"
                :key="stage.key"
                type="button"
                class="rounded-lg border px-3 py-2 text-left transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                :class="filters.stage === stage.key ? 'border-brand-400 bg-brand-50 ring-1 ring-brand-400' : 'border-slate-200 bg-white hover:border-brand-300'"
                :aria-pressed="filters.stage === stage.key"
                @click="filterStage(stage.key)"
            >
                <span class="block text-xs text-ink-500">{{ stage.label }}</span>
                <span class="mt-0.5 block text-lg leading-tight font-semibold tnum text-ink-900">{{ pcs(counts[stage.key] ?? 0) }}</span>
                <span class="block text-xs text-ink-500">{{ stage.hint }}</span>
            </button>
        </nav>

        <Card :padded="false">
            <FilterBar :only="['sheets', 'filters']" :filters="filters" :fields="filterFields" placeholder="Search sheet, quotation, order, job, product or buyer…" />

            <DataTable
                :only="['sheets', 'filters']"
                :columns="columns"
                :rows="sheets"
                row-key="id"
                :row-href="(row) => `/costing/sheets/${row.id}`"
                empty="No cost sheet yet. A sheet is made on a quotation line, at an order's confirmation and at a job's close."
            >
                <template #cell:number="{ row, value }">
                    <span class="font-medium text-ink-900">{{ value ?? `#${row.id}` }}</span>
                    <span v-if="row.revision_no > 0" class="ml-1 text-xs text-ink-500">rev {{ row.revision_no }}</span>
                </template>
                <template #cell:stage="{ value }"><Badge :tone="stageTone[value] ?? 'neutral'" :label="titleCase(value)" /></template>
                <template #cell:document="{ value }">
                    <Link v-if="value" :href="value.href" class="doc-link-quiet" @click.stop>{{ value.label }}</Link>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:product_code="{ row, value }">
                    <span class="font-medium whitespace-nowrap text-ink-900">{{ value }}</span>
                    <span class="block max-w-56 truncate text-xs text-ink-500" :title="row.product_name">{{ row.product_name }}</span>
                </template>
                <template #cell:customer_name="{ value }">{{ value ?? '—' }}</template>
                <template #cell:basis_qty="{ value }">{{ pcs(value) }}</template>
                <template #cell:unit_cost="{ row, value }">{{ moneyPerThousand(value, row.currency) }}</template>
                <template #cell:revenue_per_unit="{ row, value }">{{ value === null ? '—' : moneyPerThousand(value, row.currency) }}</template>
                <template #cell:margin="{ value }">
                    <span v-if="value === null" class="text-ink-400">—</span>
                    <span v-else class="tnum" :class="value < marginFloorPct ? 'font-medium text-rose-700' : ''">{{ pct(value, 1) }}</span>
                </template>
                <template #cell:status="{ row, value }">
                    <Badge :tone="statusTone[value] ?? 'neutral'" :label="titleCase(value)" />
                    <Badge v-if="row.is_locked" tone="neutral" label="Locked" class="ml-1" />
                </template>
                <template #cell:created_at="{ value }">{{ date(value) }}</template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
