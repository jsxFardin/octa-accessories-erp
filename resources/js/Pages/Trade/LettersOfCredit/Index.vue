<script setup>
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import ExportDialog from '@/Components/Ui/ExportDialog.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    letters: Object,
    filters: Object,
    suppliers: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

/**
 * Days to expiry, as a number the eye reads rather than two dates it has to subtract.
 *
 * An expired credit is not a warning, it is money already spent: the bank will not pay, and
 * an amendment after the fact costs a fee and a week.
 */
function daysLeft(value) {
    if (!value) return null;

    return Math.round((new Date(value) - new Date()) / 86400000);
}

function expiryTone(row) {
    const days = daysLeft(row.expiry_date);

    if (days === null || ['closed', 'cancelled', 'retired'].includes(row.status)) return 'neutral';
    if (days < 0) return 'danger';
    if (days <= 14) return 'warning';

    return 'neutral';
}

const columns = [
    { key: 'number', label: 'Ours', sort: true },
    { key: 'lc_no', label: 'Bank LC no' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'kind', label: 'Kind' },
    { key: 'amount', label: 'Amount', align: 'right', sort: true },
    { key: 'last_shipment_date', label: 'Last shipment' },
    { key: 'expiry_date', label: 'Expiry', sort: true },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="Letters of credit" />

        <template #title>Letters of credit</template>
        <template #subtitle>The credits raw material is bought against, and the two dates that cost money when missed</template>

        <template #actions>
            <ExportDialog v-if="can('letter_of_credit.export')" resource="letters-of-credit" />
            <Button v-if="can('letter_of_credit.create')" variant="primary" href="/letters-of-credit/create">New credit</Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'status', label: 'Status', options: statuses.map((s) => ({ value: s, label: titleCase(s) })) },
                    { key: 'kind', label: 'Kind', options: kinds.map((k) => ({ value: k, label: titleCase(k) })) },
                    { key: 'supplier', label: 'Supplier', options: suppliers.map((s) => ({ value: String(s.id), label: s.name })) },
                ]"
                placeholder="Search our number or the bank's…"
            />

            <DataTable
                :columns="columns"
                :rows="letters"
                row-key="id"
                :row-href="(row) => `/letters-of-credit/${row.id}`"
                empty="No credits match these filters."
            >
                <template #cell:number="{ value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:lc_no="{ value }">
                    <span v-if="value" class="font-mono text-xs">{{ value }}</span>
                    <span v-else class="text-ink-400">not yet opened</span>
                </template>
                <template #cell:kind="{ value }">{{ titleCase(value) }}</template>
                <template #cell:amount="{ value }">{{ money(value) }}</template>
                <template #cell:last_shipment_date="{ value }">{{ value ? date(value) : '—' }}</template>
                <template #cell:expiry_date="{ row, value }">
                    <span v-if="!value" class="text-ink-400">—</span>
                    <span v-else class="flex items-center gap-1.5">
                        {{ date(value) }}
                        <Badge
                            v-if="expiryTone(row) !== 'neutral'"
                            :tone="expiryTone(row)"
                            :label="daysLeft(value) < 0 ? 'expired' : `${daysLeft(value)}d`"
                        />
                    </span>
                </template>
                <template #cell:status="{ value }"><Badge :status="value" /></template>

                <template #empty>
                    <EmptyState
                        icon="ship"
                        title="No letters of credit yet"
                        description="Yarn, ribbon and ink are imported, and nearly every one of those orders is paid through a credit. Recording it here is what lets a shipment, its duty and its true cost find each other later."
                        :action-label="can('letter_of_credit.create') ? 'New credit' : null"
                        action-href="/letters-of-credit/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
