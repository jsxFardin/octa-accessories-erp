<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, pcs, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    inspection: { type: Object, required: true },
    jobCard: { type: Object, default: null },
    ncr: { type: Object, default: null },
    defects: { type: Array, default: () => [] },
});
</script>

<template>
    <AppLayout>
        <Head :title="inspection.number" />

        <template #title>{{ inspection.number }}</template>
        <template #subtitle>
            {{ titleCase(inspection.stage) }} inspection · {{ date(inspection.inspected_on) }}
            <Link v-if="jobCard" :href="`/job-cards/${jobCard.id}`" class="hover:underline"> · {{ jobCard.number }}</Link>
        </template>

        <template #actions><Badge :status="inspection.result" /></template>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2" title="Sampling plan" rule="BR-30" subtitle="ISO 2859-1, General Inspection Level II, AQL 2.5">
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs text-ink-500">Lot size</dt><dd class="text-lg font-semibold tnum">{{ pcs(inspection.lot_size) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Sample size</dt><dd class="text-lg font-semibold tnum">{{ pcs(inspection.sample_size) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Accept at</dt><dd class="text-lg font-semibold tnum text-emerald-700">≤ {{ inspection.accept_number }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Reject at</dt><dd class="text-lg font-semibold tnum text-rose-600">≥ {{ inspection.reject_number }}</dd></div>
                </dl>

                <div class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs text-ink-500">Critical</dt><dd class="font-semibold tnum text-rose-600">{{ inspection.critical_found }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Major</dt><dd class="font-semibold tnum">{{ inspection.major_found }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Minor</dt><dd class="font-semibold tnum">{{ inspection.minor_found }}</dd></div>
                    <div><dt class="text-xs text-ink-500">DHU</dt><dd class="font-semibold tnum">{{ inspection.dhu }}</dd></div>
                </div>

                <p class="mt-4 rounded bg-slate-50 px-3 py-2 text-xs text-ink-700">
                    A single critical defect rejects the lot regardless of the plan — a wrong care symbol
                    on a garment label is a recall, not a statistic.
                </p>
            </Card>

            <Card title="Disposition" rule="BR-33 · QC2">
                <div v-if="inspection.disposition">
                    <Badge tone="warning" :label="titleCase(inspection.disposition)" />
                    <p v-if="inspection.disposition_ref" class="mt-2 text-sm text-ink-700">{{ inspection.disposition_ref }}</p>
                </div>
                <p v-else-if="inspection.result === 'rejected'" class="text-sm text-rose-700">
                    Rejected with no disposition — the database refuses this row, so it cannot exist.
                </p>
                <p v-else class="text-sm text-ink-500">Accepted; no disposition needed.</p>

                <p v-if="inspection.remarks" class="mt-3 text-sm text-ink-700">{{ inspection.remarks }}</p>

                <p v-if="ncr" class="mt-4 text-sm">
                    NCR
                    <Link :href="`/ncrs/${ncr.id}`" class="font-medium text-brand-700">{{ ncr.number }}</Link>
                    <Badge :status="ncr.status" class="ml-1" />
                </p>
            </Card>

            <Card class="lg:col-span-3" title="Defects found" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'code', label: 'Code' },
                        { key: 'name', label: 'Defect' },
                        { key: 'process', label: 'Process' },
                        { key: 'severity', label: 'Severity' },
                        { key: 'qty', label: 'Count', align: 'right' },
                    ]"
                    :rows="defects"
                    row-key="id"
                    empty="No defects recorded."
                    dense
                >
                    <template #cell:process="{ value }">{{ titleCase(value) }}</template>
                    <template #cell:severity="{ value }">
                        <Badge :tone="value === 'critical' ? 'danger' : value === 'major' ? 'warning' : 'neutral'" :label="titleCase(value)" />
                    </template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
