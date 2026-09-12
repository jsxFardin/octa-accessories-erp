<script setup>
/**
 * One material issue, and the lots it actually moved.
 *
 * This document had an index and a create form and nothing in between, which meant the one
 * question a store keeper or a quality auditor asks about it — *which lot went into this
 * job?* — could be answered from the database and from nowhere on screen. It matters twice
 * over: it is the shade-traceability record (BR-37) and it is what priced the job.
 *
 * Read-only on purpose. A posted stock movement is reversed by a return, never edited, so
 * there is no edit affordance here to imply otherwise.
 */
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivityTrail from '@/Components/Ui/ActivityTrail.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { baseCurrency, date, datetime, money, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';

const props = defineProps({
    issue: { type: Object, required: true },
    /** The card this material was issued against. */
    jobCard: { type: Object, default: null },
    lines: { type: Array, default: () => [] },
    trail: { type: Array, default: () => [] },
});

const lineColumns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'item_code', label: 'Item' },
    { key: 'lot_no', label: 'Lot' },
    { key: 'shade_code', label: 'Shade' },
    { key: 'qty', label: 'Quantity', align: 'right' },
    { key: 'unit_cost', label: `Unit cost (${baseCurrency()})`, align: 'right' },
    { key: 'value', label: `Value (${baseCurrency()})`, align: 'right' },
];

function lineValue(line) {
    return Number(line.qty) * Number(line.unit_cost);
}
</script>

<template>
    <AppLayout :crumb="issue.number ?? 'Issue'">
        <Head :title="issue.number ?? 'Material issue'" />

        <template #title>{{ issue.number ?? `(unnumbered issue #${issue.id})` }}</template>
        <template #subtitle>
            {{ titleCase(issue.issue_type) }} · {{ date(issue.issued_on) }}
            <span v-if="issue.warehouse"> · from {{ issue.warehouse.code }} {{ issue.warehouse.name }}</span>
            <span v-if="jobCard">
                · for
                <Link :href="`/job-cards/${jobCard.id}`" class="doc-link">job card {{ jobCard.number ?? `#${jobCard.id}` }}</Link>
            </span>
        </template>

        <!-- Status, then the way back to the job this served. A posted movement offers no edit. -->
        <template #actions>
            <Badge :status="issue.status" />
            <Button v-if="jobCard" size="sm" variant="primary" :href="`/job-cards/${jobCard.id}`">
                Open job card
            </Button>
            <Button
                v-if="jobCard && can('stock_issue.create')"
                size="sm"
                :href="`/material-issues/create?job_card=${jobCard.id}`"
            >
                Issue more material
            </Button>
            <ActivityTrail :entries="trail" />
        </template>

        <div class="space-y-4">
            <!-- What this material was for, so the page is not a list of lot numbers in a void. -->
            <Card v-if="jobCard" title="Issued against">
                <dl class="flex flex-wrap gap-8 text-sm">
                    <div>
                        <dt class="text-ink-500">Job card</dt>
                        <dd>
                            <Link :href="`/job-cards/${jobCard.id}`" class="doc-link">{{ jobCard.number ?? `#${jobCard.id}` }}</Link>
                        </dd>
                    </div>
                    <div><dt class="text-ink-500">Status</dt><dd><Badge :status="jobCard.status" /></dd></div>
                    <div>
                        <dt class="text-ink-500">Product</dt>
                        <dd class="font-medium">{{ jobCard.product_code }} — {{ jobCard.product_name }}</dd>
                    </div>
                    <div><dt class="text-ink-500">Planned</dt><dd class="tnum">{{ qty(jobCard.planned_qty, 0) }} pcs</dd></div>
                    <div v-if="jobCard.colourway"><dt class="text-ink-500">Colourway</dt><dd>{{ jobCard.colourway }}</dd></div>
                </dl>
            </Card>

            <Card title="Lots moved" :padded="false">
                <DataTable :columns="lineColumns" :rows="lines" row-key="id" empty="No lines on this issue." dense>
                    <template #cell:item_code="{ row }">
                        <span class="font-medium text-ink-800">{{ row.item_code }}</span>
                        <span class="block text-[11px] text-ink-500">{{ row.item_name }}</span>
                    </template>
                    <template #cell:lot_no="{ row }">
                        <Link v-if="row.lot_id" :href="`/lots/${row.lot_id}`" class="doc-link-quiet font-mono text-xs">
                            {{ row.lot_no }}
                        </Link>
                        <span v-else class="text-ink-400">—</span>
                    </template>
                    <!-- BR-37 — shade is the difference between one batch and a customer claim. -->
                    <template #cell:shade_code="{ value }">
                        <span v-if="value" class="font-mono text-xs">{{ value }}</span>
                        <span v-else class="text-ink-400">—</span>
                    </template>
                    <template #cell:qty="{ row }">{{ qty(row.qty) }} {{ row.uom ?? '' }}</template>
                    <!-- Stock is valued in the factory's own currency; the header says so once. -->
                    <template #cell:unit_cost="{ row }">{{ money(row.unit_cost, false) }}</template>
                    <template #cell:value="{ row }">{{ money(lineValue(row), false) }}</template>

                    <template #footer>
                        <tr>
                            <td colspan="6" class="px-3 py-2 text-right text-sm text-ink-600">Total issued value</td>
                            <td class="px-3 py-2 text-right text-sm font-semibold tnum">
                                {{ money(issue.total_value) }}
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </Card>

            <Card title="Record">
                <dl class="flex flex-wrap gap-8 text-sm">
                    <div><dt class="text-ink-500">Issued by</dt><dd>{{ issue.issued_by ?? '—' }}</dd></div>
                    <div><dt class="text-ink-500">Received by</dt><dd>{{ issue.received_by ?? '—' }}</dd></div>
                    <div><dt class="text-ink-500">Posted</dt><dd>{{ datetime(issue.created_at) }}</dd></div>
                </dl>
                <p v-if="issue.remarks" class="mt-2 text-sm whitespace-pre-line text-ink-700">{{ issue.remarks }}</p>
            </Card>

            <!-- Any FIFO departure is a decision someone made and has to be able to defend. -->
            <Card
                v-if="lines.some((line) => line.fifo_override_reason)"
                title="FIFO overrides"
                subtitle="A lot taken out of order, and why."
            >
                <ul class="space-y-1.5 text-sm">
                    <li v-for="line in lines.filter((l) => l.fifo_override_reason)" :key="line.id">
                        <span class="font-mono text-xs">{{ line.lot_no }}</span>
                        — {{ line.fifo_override_reason }}
                    </li>
                </ul>
            </Card>
        </div>
    </AppLayout>
</template>
