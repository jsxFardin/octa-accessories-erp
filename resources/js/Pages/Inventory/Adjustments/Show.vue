<script setup>
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, datetime, money, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    adjustment: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    approval: { type: Object, default: null },
    availableTransitions: { type: Array, default: () => [] },
    canApprove: { type: Boolean, default: false },
    audit: { type: Array, default: () => [] },
});

const readOnly = ['posted', 'cancelled'].includes(props.adjustment.status);

function transition(to) {
    router.post(`/stock-adjustments/${props.adjustment.id}/transition`, { to }, { preserveScroll: true });
}

function direction(qtyDelta) {
    return Number(qtyDelta) > 0 ? 'In' : 'Out';
}
</script>

<template>
    <AppLayout>
        <Head :title="adjustment.number ?? 'Stock adjustment'" />

        <template #title>{{ adjustment.number ?? '(draft adjustment)' }}</template>
        <template #subtitle>
            {{ adjustment.warehouse?.name }} · {{ date(adjustment.adjusted_on) }}
        </template>

        <template #actions>
            <Badge :status="adjustment.status" />
            <Button
                v-if="adjustment.status === 'draft' && can('stock_adjustment.update')"
                size="sm"
                :href="`/stock-adjustments/${adjustment.id}/edit`"
            >
                Edit
            </Button>
            <Button
                v-if="availableTransitions.includes('pending_approval')"
                size="sm"
                variant="primary"
                @click="transition('pending_approval')"
            >
                Submit for approval
            </Button>
            <Button
                v-if="availableTransitions.includes('draft')"
                size="sm"
                @click="transition('draft')"
            >
                Recall to draft
            </Button>
            <Button
                v-if="availableTransitions.includes('posted')"
                size="sm"
                variant="success"
                @click="transition('posted')"
            >
                Post
            </Button>
            <Button
                v-if="availableTransitions.includes('cancelled')"
                size="sm"
                variant="danger"
                @click="transition('cancelled')"
            >
                Cancel
            </Button>
        </template>

        <div class="space-y-4">
            <Card v-if="approval" title="Approval" rule="06-rbac §5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <p class="text-xs text-ink-500">Adjustment value</p>
                        <p class="text-lg font-semibold tnum text-ink-900">{{ money(approval.value) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-500">Store manager band</p>
                        <p class="text-lg font-semibold tnum text-ink-700">{{ money(approval.band) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-500">Signs off</p>
                        <p class="text-lg font-semibold text-brand-700">{{ approval.approver }}</p>
                    </div>
                </div>
                <p
                    v-if="adjustment.status === 'pending_approval' && approval.approver === 'Managing Director' && !canApprove"
                    class="mt-3 rounded bg-amber-50 px-2 py-1.5 text-xs text-amber-900"
                >
                    This value is above the store manager band. The Managing Director must post it.
                </p>
                <p
                    v-else-if="adjustment.status === 'pending_approval' && canApprove"
                    class="mt-3 text-xs text-ink-500"
                >
                    You may post this adjustment.
                </p>
            </Card>

            <Card title="Adjustment">
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-ink-500">Warehouse</dt>
                        <dd class="font-medium">{{ adjustment.warehouse?.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Adjusted on</dt>
                        <dd class="font-medium">{{ date(adjustment.adjusted_on) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Created by</dt>
                        <dd class="font-medium">{{ adjustment.creator?.name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Approved by</dt>
                        <dd class="font-medium">{{ adjustment.approver?.name ?? '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-4 whitespace-pre-line text-sm text-ink-700">{{ adjustment.reason }}</p>
                <p v-if="readOnly" class="mt-3 rounded bg-slate-50 px-2 py-1.5 text-xs text-ink-500">
                    {{ adjustment.status === 'posted'
                        ? 'Posted. A mistake is a new compensating adjustment — this document cannot be edited, cancelled or reversed.'
                        : 'Cancelled. This document is closed.' }}
                </p>
            </Card>

            <Card title="Lines" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'lot_no', label: 'Lot' },
                        { key: 'qty_delta', label: 'Qty delta', align: 'right' },
                        { key: 'direction', label: 'Direction' },
                        { key: 'unit_cost', label: 'Unit cost', align: 'right' },
                        { key: 'value', label: 'Value', align: 'right' },
                        { key: 'balance_qty', label: 'Lot balance', align: 'right' },
                        { key: 'remarks', label: 'Remarks' },
                    ]"
                    :rows="lines.map((line) => ({ ...line, direction: direction(line.qty_delta) }))"
                    row-key="id"
                    empty="No lines."
                    dense
                >
                    <template #cell:lot_no="{ row }">
                        <span class="font-mono text-xs">{{ row.lot_no }}</span>
                        <Badge v-if="row.status && row.status !== 'available'" :status="row.status" class="ml-1" />
                    </template>
                    <template #cell:qty_delta="{ value }">
                        <span class="tnum font-medium" :class="Number(value) < 0 ? 'text-amber-800' : 'text-emerald-800'">
                            {{ Number(value) > 0 ? '+' : '' }}{{ qty(value) }}
                        </span>
                    </template>
                    <template #cell:direction="{ value }">
                        <Badge :tone="value === 'In' ? 'success' : 'warning'" :label="value" />
                    </template>
                    <template #cell:unit_cost="{ value }">{{ money(value) }}</template>
                    <template #cell:value="{ value }">{{ money(value) }}</template>
                    <template #cell:balance_qty="{ value }">{{ qty(value) }}</template>
                </DataTable>
            </Card>

            <Card title="Audit" subtitle="Creation and every status change" :padded="false">
                <ul v-if="audit.length" class="divide-y divide-slate-100 text-sm">
                    <li v-for="row in audit" :key="row.id" class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2">
                        <div>
                            <span class="font-medium text-ink-800">{{ titleCase(row.event) }}</span>
                            <span v-if="row.new_values?.status" class="ml-2 text-ink-600">
                                {{ row.old_values?.status }} → {{ row.new_values.status }}
                            </span>
                        </div>
                        <div class="text-xs text-ink-500">
                            {{ row.user?.name ?? 'system' }} · {{ datetime(row.created_at) }}
                        </div>
                    </li>
                </ul>
                <p v-else class="px-4 py-6 text-center text-sm text-ink-500">No audit rows yet.</p>
            </Card>
        </div>
    </AppLayout>
</template>
