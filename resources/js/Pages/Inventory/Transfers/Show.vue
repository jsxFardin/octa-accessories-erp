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
    transfer: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
    audit: { type: Array, default: () => [] },
});

const readOnly = ['in_transit', 'received', 'cancelled'].includes(props.transfer.status);

function transition(to) {
    router.post(`/stock-transfers/${props.transfer.id}/transition`, { to }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="transfer.number ?? 'Stock transfer'" />

        <template #title>{{ transfer.number ?? '(draft transfer)' }}</template>
        <template #subtitle>
            {{ transfer.from_warehouse?.name }} → {{ transfer.to_warehouse?.name }} · {{ date(transfer.transfer_date) }}
        </template>

        <template #actions>
            <Badge :status="transfer.status" />
            <Button
                v-if="transfer.status === 'draft' && can('stock_transfer.update')"
                size="sm"
                :href="`/stock-transfers/${transfer.id}/edit`"
            >
                Edit
            </Button>
            <Button
                v-if="availableTransitions.includes('in_transit')"
                size="sm"
                variant="primary"
                @click="transition('in_transit')"
            >
                Dispatch
            </Button>
            <Button
                v-if="availableTransitions.includes('received')"
                size="sm"
                variant="success"
                @click="transition('received')"
            >
                Receive
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
            <Card title="Transfer">
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-ink-500">From</dt>
                        <dd class="font-medium">{{ transfer.from_warehouse?.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">To</dt>
                        <dd class="font-medium">{{ transfer.to_warehouse?.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Transfer date</dt>
                        <dd class="font-medium">{{ date(transfer.transfer_date) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Created by</dt>
                        <dd class="font-medium">{{ transfer.creator?.name ?? '—' }}</dd>
                    </div>
                </dl>
                <p v-if="transfer.remarks" class="mt-4 whitespace-pre-line text-sm text-ink-700">{{ transfer.remarks }}</p>
                <p v-if="readOnly" class="mt-3 rounded bg-slate-50 px-2 py-1.5 text-xs text-ink-500">
                    {{ transfer.status === 'in_transit'
                        ? 'In transit. Stock is in the transit warehouse. Receive it in full — this document cannot be cancelled.'
                        : transfer.status === 'received'
                            ? 'Received. A mistake is a new transfer — this document cannot be edited or cancelled.'
                            : 'Cancelled. This document is closed.' }}
                </p>
            </Card>

            <Card title="Lines" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'lot_no', label: 'Source lot' },
                        { key: 'qty', label: 'Qty', align: 'right' },
                        { key: 'received_qty', label: 'Received', align: 'right' },
                        { key: 'unit_cost', label: 'Unit cost', align: 'right' },
                        { key: 'transit_lot_no', label: 'Transit lot' },
                        { key: 'destination_lot_no', label: 'Destination lot' },
                    ]"
                    :rows="lines"
                    row-key="id"
                    empty="No lines."
                    dense
                >
                    <template #cell:lot_no="{ row }">
                        <span class="font-mono text-xs">{{ row.lot_no }}</span>
                        <Badge v-if="row.status && row.status !== 'available'" :status="row.status" class="ml-1" />
                    </template>
                    <template #cell:qty="{ value }">
                        <span class="tnum font-medium">{{ qty(value) }}</span>
                    </template>
                    <template #cell:received_qty="{ value }">
                        <span class="tnum">{{ qty(value) }}</span>
                    </template>
                    <template #cell:unit_cost="{ value }">{{ money(value) }}</template>
                    <template #cell:transit_lot_no="{ row }">
                        <span class="font-mono text-xs">{{ row.transit_lot_no ?? '—' }}</span>
                        <span v-if="row.transit_lot_no" class="ml-1 text-xs text-ink-500 tnum">{{ qty(row.transit_balance_qty) }}</span>
                    </template>
                    <template #cell:destination_lot_no="{ row }">
                        <span class="font-mono text-xs">{{ row.destination_lot_no ?? '—' }}</span>
                        <span v-if="row.destination_lot_no" class="ml-1 text-xs text-ink-500 tnum">{{ qty(row.destination_balance_qty) }}</span>
                    </template>
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
