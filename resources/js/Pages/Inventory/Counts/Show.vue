<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, datetime, money, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    count: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
    canPost: { type: Boolean, default: false },
    audit: { type: Array, default: () => [] },
});

const blind = computed(() => props.count.status === 'counting');
const reconciled = computed(() => ['reconciled', 'posted'].includes(props.count.status));
const readOnly = computed(() => ['posted', 'cancelled'].includes(props.count.status));

const columns = computed(() => {
    const base = [
        { key: 'lot_no', label: 'Lot' },
        { key: 'item_code', label: 'Item' },
        { key: 'bin_code', label: 'Bin' },
    ];

    if (!blind.value) {
        base.push({ key: 'system_qty', label: 'System qty', align: 'right' });
    }

    base.push(
        { key: 'counted_qty', label: 'Counted qty', align: 'right' },
    );

    if (reconciled.value) {
        base.push(
            { key: 'variance_qty', label: 'Variance', align: 'right' },
            { key: 'unit_cost', label: 'Unit cost', align: 'right' },
            { key: 'value_impact', label: 'Value impact', align: 'right' },
        );
    }

    base.push({ key: 'counted_by', label: 'Counted by' }, { key: 'remarks', label: 'Remarks' });

    return base;
});

function transition(to) {
    router.post(`/physical-counts/${props.count.id}/transition`, { to }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="count.number ?? 'Physical count'" />

        <template #title>{{ count.number ?? '(open count)' }}</template>
        <template #subtitle>
            {{ count.warehouse?.name }} · {{ date(count.counted_on) }}
        </template>

        <template #actions>
            <Badge :status="count.status" />
            <Button
                v-if="['counting', 'reconciled', 'posted'].includes(count.status) && can('physical_count.view')"
                size="sm"
                :href="`/physical-counts/${count.id}/print`"
                target="_blank"
            >
                Print blind sheet
            </Button>
            <Button
                v-if="count.status === 'counting' && can('physical_count.update')"
                size="sm"
                :href="`/physical-counts/${count.id}/edit`"
            >
                Enter counts
            </Button>
            <Button
                v-if="availableTransitions.includes('counting')"
                size="sm"
                variant="primary"
                @click="transition('counting')"
            >
                Start counting
            </Button>
            <Button
                v-if="availableTransitions.includes('reconciled')"
                size="sm"
                variant="primary"
                @click="transition('reconciled')"
            >
                Reconcile
            </Button>
            <Button
                v-if="availableTransitions.includes('posted') && canPost"
                size="sm"
                variant="success"
                @click="transition('posted')"
            >
                Post variances
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
            <Card title="Physical count">
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-ink-500">Warehouse</dt>
                        <dd class="font-medium">{{ count.warehouse?.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Count date</dt>
                        <dd class="font-medium">{{ date(count.counted_on) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Created by</dt>
                        <dd class="font-medium">{{ count.creator?.name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Lines</dt>
                        <dd class="font-medium">{{ lines.length }}</dd>
                    </div>
                </dl>
                <p v-if="blind" class="mt-3 rounded bg-slate-50 px-2 py-1.5 text-xs text-ink-500">
                    Counting in progress. System quantities and variances stay hidden until reconciliation.
                </p>
                <p v-else-if="readOnly" class="mt-3 rounded bg-slate-50 px-2 py-1.5 text-xs text-ink-500">
                    {{ count.status === 'posted'
                        ? 'Posted. Variances are on the ledger; this document cannot be edited or cancelled.'
                        : 'Cancelled. Frozen lots were released without posting.' }}
                </p>
            </Card>

            <Card title="Lines" :padded="false">
                <DataTable :columns="columns" :rows="lines" row-key="id" empty="No lines yet — start counting to snapshot lots." dense>
                    <template #cell:lot_no="{ value }">
                        <span class="font-mono text-xs">{{ value }}</span>
                    </template>
                    <template #cell:system_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:counted_qty="{ value }">{{ value === null || value === '' ? '—' : qty(value) }}</template>
                    <template #cell:variance_qty="{ value }">
                        <span class="tnum font-medium" :class="Number(value) < 0 ? 'text-amber-800' : Number(value) > 0 ? 'text-emerald-800' : ''">
                            {{ Number(value) > 0 ? '+' : '' }}{{ qty(value) }}
                        </span>
                    </template>
                    <template #cell:unit_cost="{ value }">{{ money(value) }}</template>
                    <template #cell:value_impact="{ value }">{{ money(value) }}</template>
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
