<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { pcs, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';

const props = defineProps({
    routing: { type: Object, required: true },
    operations: { type: Array, default: () => [] },
    totalWastagePct: { type: [Number, String], default: 0 },
    products: { type: Array, default: () => [] },
});

const columns = [
    { key: 'sequence_no', label: '#', align: 'center', width: '3rem' },
    { key: 'code', label: 'Operation' },
    { key: 'machine_group', label: 'Machine group' },
    { key: 'std_rate_per_hour', label: 'Std rate / h', align: 'right' },
    { key: 'setup', label: 'Make-ready', align: 'right' },
    { key: 'wastage_pct', label: 'Wastage', align: 'right' },
    { key: 'manning_level', label: 'Manning', align: 'right' },
    { key: 'flags', label: '' },
];
</script>

<template>
    <AppLayout>
        <Head :title="routing.code" />

        <template #title>{{ routing.code }}</template>
        <template #subtitle>{{ routing.name }} · {{ titleCase(routing.product_type) }}</template>

        <template #actions>
            <Badge v-if="routing.is_default" tone="info" label="Default" />
            <Badge :tone="routing.is_active ? 'success' : 'neutral'" :label="routing.is_active ? 'Active' : 'Retired'" />
            <Button v-if="can('routing.update')" size="sm" :href="`/routings/${routing.id}/edit`">Edit</Button>
        </template>

        <div class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-3">
                <Card>
                    <p class="text-xs text-ink-500">Total wastage</p>
                    <p class="text-2xl font-semibold tnum text-ink-900">{{ Number(totalWastagePct).toFixed(2) }}%</p>
                    <!-- BR-8 is additive, and only over the operations that actually run the web. -->
                    <p class="mt-1 text-[11px] text-ink-500">
                        BR-8 — additive across web-consuming operations only
                    </p>
                </Card>

                <Card>
                    <p class="text-xs text-ink-500">Operations</p>
                    <p class="text-2xl font-semibold tnum text-ink-900">{{ operations.length }}</p>
                    <p class="mt-1 text-[11px] text-ink-500">
                        {{ operations.filter((o) => o.consumes_web).length }} consume the web ·
                        {{ operations.filter((o) => o.requires_qc).length }} inspected
                    </p>
                </Card>

                <Card>
                    <p class="text-xs text-ink-500">Max lot size</p>
                    <p class="text-2xl font-semibold tnum text-ink-900">
                        {{ routing.max_lot_size ? pcs(routing.max_lot_size) : '—' }}
                    </p>
                    <p class="mt-1 text-[11px] text-ink-500">{{ products.length }} product(s) use this routing</p>
                </Card>
            </div>

            <Card title="Operations" subtitle="Executed in sequence order (J2)" :padded="false">
                <DataTable :columns="columns" :rows="operations" row-key="id" empty="No operations." dense>
                    <template #cell:code="{ row }">
                        <span class="font-medium text-ink-900">{{ row.code }}</span>
                        <span class="text-ink-500"> {{ row.name }}</span>
                    </template>
                    <template #cell:machine_group="{ value }">{{ value ?? '—' }}</template>
                    <template #cell:std_rate_per_hour="{ value }">
                        <span class="tnum">{{ value ? Number(value).toLocaleString() : '—' }}</span>
                    </template>
                    <template #cell:setup="{ row }">
                        <span class="tnum">{{ Number(row.setup_minutes).toFixed(0) }} min</span>
                        <span v-if="Number(row.setup_qty) > 0" class="text-ink-500"> · {{ pcs(row.setup_qty) }}</span>
                    </template>
                    <template #cell:wastage_pct="{ row, value }">
                        <span class="tnum" :class="row.consumes_web ? 'text-ink-900' : 'text-ink-400 line-through'">
                            {{ Number(value).toFixed(2) }}%
                        </span>
                    </template>
                    <template #cell:manning_level="{ value }">
                        <span class="tnum">{{ Number(value).toFixed(2) }}</span>
                    </template>
                    <template #cell:flags="{ row }">
                        <div class="flex flex-wrap gap-1">
                            <Badge v-if="!row.consumes_web" tone="neutral" label="no web" />
                            <Badge v-if="row.allow_parallel" tone="info" label="parallel" />
                            <Badge v-if="row.requires_qc" tone="warning" label="QC" />
                        </div>
                    </template>
                </DataTable>
            </Card>

            <Card title="Products on this routing" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'code', label: 'Code' },
                        { key: 'name', label: 'Name' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="products"
                    row-key="id"
                    :row-href="(row) => `/products/${row.id}`"
                    empty="No product uses this routing yet."
                    dense
                >
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
