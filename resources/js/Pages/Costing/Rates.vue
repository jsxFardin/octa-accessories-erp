<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, money, number, pct, qty } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    factory: { type: Array, default: () => [] },
    machines: { type: Array, default: () => [] },
    materials: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

/*
 * The factory's rates are settings, edited in place here by whoever may change settings. Each
 * save goes through the settings screen's own action, so the audit trail is the same.
 */
const draft = reactive(Object.fromEntries(props.factory.map((entry) => [entry.key, entry.value ?? ''])));
const saving = reactive({ busy: false });

function saveFactory() {
    saving.busy = true;
    router.put('/admin/settings', {
        settings: props.factory.map((entry) => ({ key: entry.key, value: draft[entry.key] })),
    }, { preserveScroll: true, onFinish: () => { saving.busy = false; } });
}

const machineColumns = [
    { key: 'code', label: 'Machine' },
    { key: 'group_name', label: 'Group' },
    { key: 'std_rate_per_hour', label: 'Std rate / h', align: 'right' },
    { key: 'hourly_rate', label: 'Cost / h', align: 'right' },
    { key: 'kw_rating', label: 'kW', align: 'right' },
    { key: 'efficiency_pct', label: 'Efficiency', align: 'right' },
    { key: 'status', label: 'Status' },
];

const materialColumns = [
    { key: 'code', label: 'Material', wrap: true },
    { key: 'uom', label: 'Unit' },
    { key: 'std_rate', label: 'Standard rate', align: 'right' },
    { key: 'avg_rate', label: 'Weighted average', align: 'right' },
    { key: 'last_rate', label: 'Last paid', align: 'right' },
    { key: 'standard_wastage_pct', label: 'Std wastage', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head title="Rate master" />

        <template #title>Rate master</template>
        <template #subtitle>
            Every rate a cost sheet draws on, in one place. A sheet made today reads these; a sheet already locked keeps the rates of its day.
        </template>

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div class="min-w-0 space-y-4">
                <Card title="Material rates" rule="BR-9" subtitle="Weighted average is what the material actually cost; the standard rate stands in until stock has been received." :padded="false">
                    <FilterBar :only="['materials', 'filters']" :filters="filters" :fields="[]" placeholder="Search material code or name…" />
                    <DataTable :only="['materials', 'filters']" :columns="materialColumns" :rows="materials" row-key="id" :row-href="(row) => `/items/${row.id}`" empty="No bought material yet." dense>
                        <template #cell:code="{ row, value }">
                            <span class="font-medium text-ink-900">{{ value }}</span>
                            <span class="block text-xs text-ink-500">{{ row.name }}</span>
                        </template>
                        <template #cell:std_rate="{ value }">{{ money(value) }}</template>
                        <template #cell:avg_rate="{ value }">
                            <span :class="Number(value) > 0 ? 'font-medium' : 'text-ink-400'">{{ Number(value) > 0 ? money(value) : '—' }}</span>
                        </template>
                        <template #cell:last_rate="{ row, value }">
                            <template v-if="value !== null">{{ money(value) }}<span class="block text-xs text-ink-500">{{ date(row.last_received_on) }}</span></template>
                            <span v-else class="text-ink-400">—</span>
                        </template>
                        <template #cell:standard_wastage_pct="{ value }">{{ pct(value, 2) }}</template>
                    </DataTable>
                </Card>

                <Card title="Machine rates" rule="BR-16 · BR-18" subtitle="The rate that costs a step is the machine's, when one is nominated for the group; otherwise the routing's standard rate." :padded="false">
                    <DataTable :columns="machineColumns" :rows="machines" row-key="id" :row-href="(row) => `/machines/${row.id}`" empty="No machine yet." dense>
                        <template #cell:code="{ row, value }">
                            <span class="font-medium text-ink-900">{{ value }}</span>
                            <span class="block text-xs text-ink-500">{{ row.name }}</span>
                        </template>
                        <template #cell:std_rate_per_hour="{ value }">{{ value ? qty(value, 0) : '—' }}</template>
                        <template #cell:hourly_rate="{ value }">{{ money(value) }}</template>
                        <template #cell:kw_rating="{ value }">{{ value ?? '—' }}</template>
                        <template #cell:efficiency_pct="{ value }">{{ pct(value, 0) }}</template>
                        <template #cell:status="{ value }"><Badge :status="value" /></template>
                    </DataTable>
                </Card>
            </div>

            <aside class="order-first space-y-4 xl:order-none xl:sticky xl:top-20">
                <Card title="Factory rates" rule="BR-17 · BR-19 · BR-20" subtitle="Applied to every sheet made from now on.">
                    <form class="space-y-3" @submit.prevent="saveFactory">
                        <div v-for="entry in factory" :key="entry.key" class="flex items-center justify-between gap-3">
                            <label :for="`rate-${entry.key}`" class="min-w-0 text-sm text-ink-700" :title="entry.hint">
                                {{ entry.label }}
                                <span class="block text-xs text-ink-500">{{ entry.unit }}</span>
                            </label>
                            <div class="w-28 shrink-0">
                                <TextInput v-if="can('setting.update')" :id="`rate-${entry.key}`" v-model="draft[entry.key]" type="number" step="0.01" min="0" numeric />
                                <span v-else class="block text-right text-sm tnum text-ink-900">{{ number(entry.value, 2) }}</span>
                            </div>
                        </div>
                        <div v-if="can('setting.update')" class="flex justify-end pt-1">
                            <Button type="submit" size="sm" variant="primary" :loading="saving.busy">Save rates</Button>
                        </div>
                    </form>
                    <p class="mt-3 text-xs text-ink-500">
                        Labour rate and electricity tariff price the routing's hours; overheads sit on direct cost; the margin floor is where a sheet needs approval.
                        <Link href="/admin/settings" class="doc-link-quiet">All settings</Link>
                    </p>
                </Card>
            </aside>
        </div>
    </AppLayout>
</template>
