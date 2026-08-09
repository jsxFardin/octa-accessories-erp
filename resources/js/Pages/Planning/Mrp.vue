<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, datetime, qty } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    runs: { type: Array, default: () => [] },
    run: { type: Object, default: null },
    requirements: { type: Array, default: () => [] },
});

const form = useForm({ horizon_days: 60 });

const columns = [
    { key: 'item_code', label: 'Item' },
    { key: 'gross_req_qty', label: 'Gross', align: 'right' },
    { key: 'on_hand_qty', label: 'On hand', align: 'right' },
    { key: 'reserved_qty', label: 'Reserved', align: 'right' },
    { key: 'on_order_qty', label: 'On order', align: 'right' },
    { key: 'net_req_qty', label: 'Net', align: 'right' },
    { key: 'suggested_po_qty', label: 'Suggested PO', align: 'right' },
    { key: 'need_date', label: 'Need by' },
    { key: 'po_place_by', label: 'Place by' },
    { key: 'is_shortage', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head title="MRP" />

        <template #title>Material requirements planning</template>
        <template #subtitle>
            BR-24 … BR-26. The output of a run is persisted, so a planner can answer
            “what did the system tell me on Tuesday?”
        </template>

        <template #actions>
            <input v-model="form.horizon_days" type="number" class="form-input w-24 text-right tnum" min="7" max="180">
            <Button v-if="can('mrp.run')" variant="primary" :loading="form.processing" @click="form.post('/mrp/run')">
                Run MRP
            </Button>
        </template>

        <div class="grid gap-4 xl:grid-cols-4">
            <Card class="xl:col-span-1" title="Runs" :padded="false">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li
                        v-for="r in runs"
                        :key="r.id"
                        class="cursor-pointer px-3 py-2 hover:bg-slate-50"
                        :class="run?.id === r.id ? 'bg-brand-50' : ''"
                        @click="router.get('/mrp', { run: r.id }, { preserveState: true })"
                    >
                        <p class="font-medium text-ink-800">{{ datetime(r.run_at) }}</p>
                        <p class="text-xs text-ink-500">
                            {{ date(r.horizon_from) }} → {{ date(r.horizon_to) }}
                        </p>
                        <Badge :tone="r.shortage_count > 0 ? 'warning' : 'success'" :label="`${r.shortage_count} shortage(s)`" />
                    </li>
                    <li v-if="runs.length === 0" class="px-3 py-6 text-center text-ink-500">No runs yet.</li>
                </ul>
            </Card>

            <Card class="xl:col-span-3" title="Requirements" rule="BR-24 · BR-25 · BR-26" :padded="false">
                <DataTable :columns="columns" :rows="requirements" row-key="id" empty="Run MRP to see requirements." dense>
                    <template #cell:item_code="{ row }">
                        <span class="font-medium">{{ row.item_code }}</span>
                        <span class="text-ink-500"> {{ row.item_name }}</span>
                    </template>
                    <template #cell:gross_req_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:on_hand_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:reserved_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:on_order_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:net_req_qty="{ value }">
                        <span :class="Number(value) > 0 ? 'font-medium text-rose-600' : ''">{{ qty(value) }}</span>
                    </template>
                    <template #cell:suggested_po_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:need_date="{ value }">{{ date(value) }}</template>
                    <template #cell:po_place_by="{ value }">
                        <!-- A place-by date in the past is already late; say so rather than imply it -->
                        <span :class="new Date(value) < new Date() ? 'font-medium text-rose-600' : ''">{{ date(value) }}</span>
                    </template>
                    <template #cell:is_shortage="{ value }">
                        <Badge :tone="value ? 'danger' : 'success'" :label="value ? 'Shortage' : 'Covered'" />
                    </template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
