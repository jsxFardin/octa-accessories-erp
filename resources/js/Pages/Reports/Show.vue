<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { baseCurrency, date, money, pcs, pct, qty, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    report: { type: Object, required: true },
    rows: { type: Object, required: true },
    totals: { type: Object, default: () => ({}) },
    extras: { type: Object, default: () => ({}) },
    applied: { type: Object, default: () => ({}) },
    /** BR-50 — what the totals row is in, and what it was made from. */
    totalsMeta: { type: Object, default: () => ({ converted: false, mixed: false, by_currency: {} }) },
});

const totalColumns = computed(() =>
    (props.report.columns ?? []).filter((column) => props.totals[column.key] !== undefined),
);

const reconciliation = computed(() => props.extras?.reconciliation ?? null);
const movements = computed(() => props.extras?.movements ?? []);

/**
 * BR-50 — a money cell is labelled with the currency of the document it came from.
 *
 * `money(value)` alone falls back to the factory's currency, which is how a USD 11.63 invoice
 * came to be reported as BDT 11.63. The row carries its own code; where a report is
 * single-currency there is none and the base-currency fallback is correct.
 */
function formatValue(column, value, row = null) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    switch (column.format) {
        case 'qty':
            return Number.isInteger(Number(value)) ? pcs(value) : qty(value);
        case 'money':
            return money(value, row?.currency ?? undefined);
        case 'date':
            return date(value);
        case 'pct':
            return pct(value);
        default:
            return value;
    }
}

/**
 * The totals row is always in the factory's own currency: on a mixed set it is the only
 * aggregate that means anything, and it is reached by converting each document at the rate
 * that document itself recorded (BR-22).
 */
function formatTotal(column) {
    const value = props.totals[column.key];

    if (column.format !== 'money') {
        return formatValue(column, value);
    }

    return money(value, baseCurrency());
}

/** The currencies behind a converted total, so the figure can be checked rather than trusted. */
const currencyBreakdown = computed(() => {
    const by = props.totalsMeta?.by_currency ?? {};

    return Object.entries(by).map(([code, amounts]) => ({ code, amounts }));
});

function rowHref(row) {
    if (!props.report.document_path || !row?.id) {
        return null;
    }

    return `${props.report.document_path}/${row.id}`;
}
</script>

<template>
    <AppLayout>
        <Head :title="report.title" />

        <template #title>{{ report.title }}</template>
        <template #subtitle>{{ report.subtitle }}</template>

        <div class="space-y-4">
            <div
                v-if="reconciliation"
                class="rounded-lg border px-3 py-2 text-sm"
                :class="reconciliation.mismatched.length
                    ? 'border-rose-200 bg-rose-50 text-rose-900'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-900'"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Badge
                        :tone="reconciliation.mismatched.length ? 'danger' : 'success'"
                        :label="reconciliation.mismatched.length ? 'Mismatch' : 'Reconciled'"
                    />
                    <span class="font-medium">
                        {{ reconciliation.checked }} lot balance(s) checked against the live ledger
                    </span>
                </div>
            </div>

            <div
                v-if="movements.length"
                class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-ink-600"
            >
                <p class="mb-1 font-medium text-ink-700">
                    Ledger movements
                    <span v-if="extras.movement_period" class="font-normal text-ink-400">
                        {{ extras.movement_period.from }} → {{ extras.movement_period.to }}
                    </span>
                </p>
                <div class="flex flex-wrap gap-3">
                    <span v-for="row in movements" :key="row.movement_type" class="tnum">
                        {{ titleCase(row.movement_type) }}: {{ qty(row.qty) }}
                        <span class="text-ink-400">({{ row.movements }})</span>
                    </span>
                </div>
            </div>

            <Card :padded="false">
                <FilterBar
                    :filters="applied"
                    :fields="report.filters"
                    placeholder="Search document number…"
                />

                <div
                    v-if="totalColumns.length"
                    class="grid grid-cols-2 gap-2 border-b border-slate-100 bg-slate-50/70 px-3 py-2 sm:grid-cols-4 xl:grid-cols-6"
                >
                    <div v-for="column in totalColumns" :key="column.key">
                        <dt class="text-[11px] text-ink-500">{{ column.label }}</dt>
                        <dd class="tnum text-sm font-semibold text-ink-900">
                            {{ formatTotal(column) }}
                        </dd>
                    </div>
                </div>

                <!--
                    BR-50. Adding a dollar invoice to a taka one at face value is not a total,
                    it is a wrong number. Where the set really is mixed, say so, say what the
                    converted figure is in, and show the untouched amounts it came from.
                -->
                <p
                    v-if="totalsMeta.mixed"
                    class="border-b border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900"
                >
                    These rows span more than one currency. The totals above are converted to
                    <span class="font-medium">{{ baseCurrency() }}</span> at the rate recorded on each
                    document (BR-22). Before conversion:
                    <span v-for="(group, index) in currencyBreakdown" :key="group.code">
                        <span v-if="index">; </span>
                        <span class="font-medium">{{ group.code }}</span>
                        <template v-for="(amount, key) in group.amounts" :key="key">
                            {{ ' ' }}{{ money(amount, group.code) }}
                        </template>
                    </span>.
                </p>

                <DataTable
                    :columns="report.columns"
                    :rows="rows"
                    row-key="id"
                    :row-href="report.document_path ? rowHref : null"
                    empty="Nothing matches these filters."
                >
                    <template v-for="column in report.columns" :key="column.key" #[`cell:${column.key}`]="{ row, value }">
                        <span v-if="column.key === 'number'" class="doc-link-quiet">{{ value ?? '—' }}</span>
                        <Badge v-else-if="column.format === 'status'" :status="value" />
                        <Badge
                            v-else-if="column.key === 'overdue' || column.key === 'is_overdue'"
                            :tone="value === 'yes' ? 'danger' : 'neutral'"
                            :label="titleCase(value)"
                        />
                        <span v-else>{{ formatValue(column, value, row) }}</span>
                    </template>
                    <template #empty>
                        <EmptyState
                            icon="reports"
                            title="No rows"
                            description="Reports are read-only. Change the date range or filters, or wait for transactional activity."
                            :filtered="Object.entries(applied ?? {}).some(([key, value]) => key !== 'sort' && value)"
                            @clear-filters="router.get(`/reports/${report.key}`)"
                        />
                    </template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
