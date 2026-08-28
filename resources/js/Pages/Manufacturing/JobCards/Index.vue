<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import ExportDialog from '@/Components/Ui/ExportDialog.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import CodeName from '@/Components/Ui/CodeName.vue';

const props = defineProps({ jobCards: Object, filters: Object });

/**
 * F-08 — a list a work queue lands on should offer the work.
 *
 * `material_pending` is deliberately absent from the issue action: material moves against a
 * card that has been released, and the state machine takes a card from `material_pending` to
 * `released`, not straight to an issue. Offering "Issue material" on the queue that is named
 * after waiting for material would have looked helpful and led to a form that refuses to
 * preselect the card. The statuses here are the ones `MaterialIssueController::create()`
 * actually accepts; the server remains the authority either way.
 */
const ISSUABLE_STATUSES = ['released', 'in_production', 'qc_pending', 'completed'];

function rowActions(row) {
    return [
        {
            label: 'Issue material',
            hidden: !can('stock_issue.create') || !ISSUABLE_STATUSES.includes(row.status),
            onSelect: () => router.visit(`/material-issues/create?job_card=${row.id}`),
        },
        { label: 'Open', onSelect: () => router.visit(`/job-cards/${row.id}`) },
    ];
}

// Status and Due beside the number — the two columns anyone actually scans were the two
// that sat behind the horizontal scrollbar.
const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'status', label: 'Status', sort: true },
    { key: 'due_date', label: 'Due', sort: true },
    { key: 'product', label: 'Product' },
    { key: 'colourway', label: 'Colourway' },
    { key: 'planned_qty', label: 'Planned', align: 'right', sort: true },
    { key: 'good_qty', label: 'Good', align: 'right' },
    { key: 'waste_qty', label: 'Waste', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head title="Job cards" />

        <template #title>Job cards</template>
        <template #subtitle>Bound to a routing and an approved artwork version</template>

        <template #actions>
            <ExportDialog v-if="can('job_card.export')" resource="job-cards" />
            <Button v-if="can('job_card.create')" variant="primary" href="/job-cards/create">New job card</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','planned','material_pending','released','in_production','on_hold','qc_pending','completed','closed','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search job card number or colourway…" />

            <DataTable
                :columns="columns"
                :rows="jobCards"
                row-key="id" :actions="rowActions" :row-href="(row) => `/job-cards/${row.id}`"
                empty="No job cards match these filters."
            >
                <template #cell:number="{ row, value }"><span class="doc-link-quiet">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:product="{ row }"><CodeName v-if="row.product" :code="row.product.code" :name="row.product.name" /></template>
                <template #cell:planned_qty="{ row, value }">{{ pcs(value) }}</template>
                <template #cell:good_qty="{ row, value }">{{ pcs(value) }}</template>
                <template #cell:waste_qty="{ row, value }">{{ pcs(value) }}</template>
                <template #cell:due_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="job-card"
                        title="No job cards yet"
                        description="A job card binds an approved artwork version and snapshots the consumption plan it will be costed against."
                        :action-label="can('job_card.create') ? 'New job card' : null"
                        action-href="/job-cards/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
