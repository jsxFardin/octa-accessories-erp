<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ issues: Object, filters: Object });

// The list said "number, date, type, status" and nothing about what the material was *for*,
// which is the column anyone scanning it is looking for.
const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'issued_on', label: 'Date', sort: true },
    { key: 'job_card_number', label: 'Job card' },
    { key: 'warehouse', label: 'From' },
    { key: 'issue_type', label: 'Type' },
    { key: 'line_count', label: 'Lots', align: 'center' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Material issues" />

        <template #title>Material issues</template>
        <template #subtitle>Shade-first suggestions with a FIFO fallback; overrides are logged</template>

        <template #actions>
            <Button v-if="can('stock_issue.create')" variant="primary" href="/material-issues/create">New issue</Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','posted','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search issue number…" />

            <DataTable
                :columns="columns"
                :rows="issues"
                row-key="id"
                :row-href="(row) => `/material-issues/${row.id}`"
                empty="No issues posted."
            >
                <template #cell:number="{ row, value }"><span class="doc-link-quiet">{{ value ?? `(unnumbered #${row.id})` }}</span></template>
                <template #cell:job_card_number="{ row, value }">
                    <Link
                        v-if="row.job_card_id"
                        :href="`/job-cards/${row.job_card_id}`"
                        class="doc-link-quiet"
                    >{{ value ?? `#${row.job_card_id}` }}</Link>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:warehouse="{ value }">{{ value ?? '—' }}</template>
                <template #cell:line_count="{ value }">{{ value }}</template>
                <template #cell:issued_on="{ row, value }">{{ date(value) }}</template>
                <template #cell:issue_type="{ row, value }">{{ titleCase(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="issue"
                        title="Nothing issued yet"
                        description="Material is issued against a job card, shade-first with a FIFO fallback."
                        :action-label="can('stock_issue.create') ? 'New issue' : null"
                        action-href="/material-issues/create"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
