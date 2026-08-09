<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { date, datetime, pcs, qty, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    jobCard: { type: Object, required: true },
    operations: { type: Array, default: () => [] },
    releaseGate: { type: Object, required: true },
    availableTransitions: { type: Array, default: () => [] },
    bomRequirement: { type: Array, default: () => [] },
    issues: { type: Array, default: () => [] },
    wasteLogs: { type: Array, default: () => [] },
});

const releaseOpen = ref(false);
const holdOpen = ref(false);

const releaseForm = useForm({ to: 'released', material_waiver_reason: '' });
const holdForm = useForm({ to: 'on_hold', hold_reason: '' });

const checks = computed(() => Object.values(props.releaseGate.checks));

const progressPct = computed(() => {
    const planned = Number(props.jobCard.planned_qty) || 1;

    return Math.min(100, (Number(props.jobCard.good_qty) / planned) * 100);
});

const overrunBreached = computed(
    () => Number(props.jobCard.produced_qty) > Number(props.jobCard.overrun_ceiling),
);

function transition(to) {
    router.post(`/job-cards/${props.jobCard.id}/transition`, { to }, { preserveScroll: true });
}

function release() {
    releaseForm.post(`/job-cards/${props.jobCard.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => {
            releaseOpen.value = false;
            releaseForm.reset();
        },
    });
}

function hold() {
    holdForm.post(`/job-cards/${props.jobCard.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => {
            holdOpen.value = false;
            holdForm.reset();
        },
    });
}

const operationColumns = [
    { key: 'sequence_no', label: '#', align: 'center', width: '3rem' },
    { key: 'name', label: 'Operation' },
    { key: 'machine', label: 'Machine' },
    { key: 'planned_qty', label: 'Planned', align: 'right' },
    { key: 'input_qty', label: 'Input', align: 'right' },
    { key: 'good_qty', label: 'Good', align: 'right' },
    { key: 'waste_qty', label: 'Waste', align: 'right' },
    { key: 'status', label: 'Status' },
];

const bomColumns = [
    { key: 'item', label: 'Item' },
    { key: 'qty_per_base', label: 'Per 1000', align: 'right' },
    { key: 'required', label: 'Required', align: 'right' },
    { key: 'formula_ref', label: 'Rule' },
];
</script>

<template>
    <AppLayout>
        <Head :title="jobCard.number ?? 'Job card'" />

        <template #title>{{ jobCard.number ?? '(unnumbered)' }}</template>
        <template #subtitle>
            {{ jobCard.product?.code }} — {{ jobCard.product?.name }}
            <span v-if="jobCard.colourway"> · {{ jobCard.colourway }}</span>
            <span v-if="jobCard.customer"> · {{ jobCard.customer.name }}</span>
        </template>

        <template #actions>
            <Badge :status="jobCard.status" />

            <Button
                v-if="availableTransitions.includes('planned')"
                size="sm"
                @click="transition('planned')"
            >
                Plan
            </Button>
            <Button
                v-if="availableTransitions.includes('released')"
                size="sm"
                variant="primary"
                @click="releaseOpen = true"
            >
                Release
            </Button>
            <Button v-if="availableTransitions.includes('on_hold')" size="sm" variant="danger" @click="holdOpen = true">
                Hold
            </Button>
            <Button v-if="availableTransitions.includes('in_production')" size="sm" @click="transition('in_production')">
                Resume
            </Button>
            <Button v-if="availableTransitions.includes('qc_pending')" size="sm" @click="transition('qc_pending')">
                Send to QC
            </Button>
            <Button v-if="availableTransitions.includes('completed')" size="sm" variant="success" @click="transition('completed')">
                Complete
            </Button>
            <Button v-if="availableTransitions.includes('closed')" size="sm" @click="transition('closed')">
                Close
            </Button>
        </template>

        <div class="space-y-4">
            <!-- J1: the four conditions, always visible -->
            <Card
                title="Release gate"
                rule="J1"
                subtitle="All four must hold before production may run. Shown whether or not you are about to release."
            >
                <ul class="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                    <li
                        v-for="check in checks"
                        :key="check.label"
                        class="rounded-md border px-3 py-2"
                        :class="check.ok ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50'"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium" :class="check.ok ? 'text-emerald-900' : 'text-rose-900'">
                                {{ check.label }}
                            </span>
                            <span class="font-mono text-[10px]" :class="check.ok ? 'text-emerald-700' : 'text-rose-700'">
                                {{ check.rule }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs" :class="check.ok ? 'text-emerald-800' : 'text-rose-800'">
                            {{ check.detail }}
                        </p>
                    </li>
                </ul>

                <div v-if="releaseGate.shortages.length" class="mt-3">
                    <p class="mb-1 text-xs font-medium text-ink-700">Shortages (BR-24)</p>
                    <table class="min-w-full text-xs">
                        <thead class="text-ink-500">
                            <tr>
                                <th class="py-1 text-left">Item</th>
                                <th class="py-1 text-right">Required</th>
                                <th class="py-1 text-right">Available</th>
                                <th class="py-1 text-right">On order</th>
                                <th class="py-1 text-right">Short</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="shortage in releaseGate.shortages" :key="shortage.item_id" class="border-t border-slate-100">
                                <td class="py-1">{{ shortage.item_code }} — {{ shortage.item_name }}</td>
                                <td class="py-1 text-right tnum">{{ qty(shortage.required) }}</td>
                                <td class="py-1 text-right tnum">{{ qty(shortage.available) }}</td>
                                <td class="py-1 text-right tnum">{{ qty(shortage.on_order) }}</td>
                                <td class="py-1 text-right font-medium tnum text-rose-600">{{ qty(shortage.short) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <div class="grid gap-4 xl:grid-cols-3">
                <!-- Consumption snapshot -->
                <Card
                    title="Consumption plan"
                    rule="BR-4 … BR-13"
                    subtitle="Snapshotted at planning. A later spec revision does not change what the floor produces to."
                >
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-ink-500">Planned quantity</dt>
                            <dd class="font-medium tnum text-ink-900">{{ pcs(jobCard.planned_qty) }} pcs</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Gross metres</dt>
                            <dd class="font-medium tnum text-ink-900">{{ qty(jobCard.gross_metres) }} m</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Ends</dt>
                            <dd class="font-medium tnum text-ink-900">{{ jobCard.ends ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Labels / metre</dt>
                            <dd class="font-medium tnum text-ink-900">{{ qty(jobCard.labels_per_metre, 4) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Spec version</dt>
                            <dd class="font-medium text-ink-900">v{{ jobCard.spec_version }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Due</dt>
                            <dd class="font-medium text-ink-900">{{ date(jobCard.due_date) }}</dd>
                        </div>
                    </dl>
                </Card>

                <!-- Gate 1 binding -->
                <Card title="Bound artwork" rule="Gate 1 · A2">
                    <div class="space-y-2 text-sm">
                        <div class="flex items-center gap-2">
                            <Link :href="`/artworks/${jobCard.artwork.id}`" class="font-medium text-brand-700 hover:underline">
                                {{ jobCard.artwork.code }} v{{ jobCard.artwork.version_no }}
                            </Link>
                            <Badge :status="jobCard.artwork.status" />
                        </div>
                        <p class="font-mono text-[10px] break-all text-ink-400">
                            sha256 {{ jobCard.artwork.checksum }}
                        </p>
                        <p class="text-xs text-ink-500">
                            This job card is welded to this version. Superseding it upstream does not
                            change what this run prints.
                        </p>
                    </div>
                </Card>

                <!-- Output -->
                <Card title="Output" rule="J3 · J5">
                    <div class="mb-3">
                        <div class="mb-1 flex items-center justify-between text-xs text-ink-500">
                            <span>Good against planned</span>
                            <span class="tnum">{{ pcs(jobCard.good_qty) }} / {{ pcs(jobCard.planned_qty) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${progressPct}%` }" />
                        </div>
                    </div>

                    <dl class="grid grid-cols-3 gap-2 text-sm">
                        <div>
                            <dt class="text-xs text-ink-500">Produced</dt>
                            <dd class="font-medium tnum">{{ pcs(jobCard.produced_qty) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Good</dt>
                            <dd class="font-medium tnum text-emerald-700">{{ pcs(jobCard.good_qty) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Waste</dt>
                            <dd class="font-medium tnum text-rose-600">{{ pcs(jobCard.waste_qty) }}</dd>
                        </div>
                    </dl>

                    <p
                        class="mt-3 rounded px-2 py-1 text-xs"
                        :class="overrunBreached ? 'bg-rose-50 text-rose-800' : 'bg-slate-50 text-ink-700'"
                    >
                        J5 ceiling: {{ pcs(jobCard.overrun_ceiling) }} pcs
                        ({{ jobCard.overrun_tolerance_pct }}% overrun tolerance)
                    </p>
                </Card>
            </div>

            <!-- Operations -->
            <Card title="Operations" rule="J2" subtitle="Execute in sequence; a step cannot start before its predecessor closes" :padded="false">
                <DataTable :columns="operationColumns" :rows="operations" empty="No operations scheduled." dense>
                    <template #cell:name="{ row }">
                        <span class="font-medium text-ink-800">{{ row.name }}</span>
                        <Badge v-if="row.requires_qc" tone="info" label="QC" class="ml-1" />
                        <Badge v-if="!row.predecessors_complete" tone="neutral" label="blocked" class="ml-1" />
                    </template>
                    <template #cell:machine="{ row }">{{ row.machine?.code ?? row.machine_group ?? '—' }}</template>
                    <template #cell:planned_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:input_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:good_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:waste_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card title="Material requirement" rule="BR-1" subtitle="BOM scaled from per-1000 to this job's quantity" :padded="false">
                    <DataTable :columns="bomColumns" :rows="bomRequirement" empty="No BOM bound." dense>
                        <template #cell:item="{ row }">
                            <span class="font-medium">{{ row.item?.code }}</span>
                            <span class="text-ink-500"> {{ row.item?.name }}</span>
                        </template>
                        <template #cell:qty_per_base="{ value }">{{ qty(value) }}</template>
                        <template #cell:required="{ value }">{{ qty(value) }}</template>
                        <template #cell:formula_ref="{ value }">
                            <span v-if="value" class="rounded bg-slate-100 px-1 font-mono text-[10px]">{{ value }}</span>
                            <span v-else class="text-ink-400">fixed</span>
                        </template>
                    </DataTable>
                </Card>

                <Card title="Material issued" :padded="false">
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="issue in issues" :key="issue.id" class="flex items-center justify-between px-3 py-2">
                            <span class="font-medium text-ink-800">{{ issue.number }}</span>
                            <span class="text-xs text-ink-500">{{ date(issue.issued_on) }}</span>
                            <Badge :status="issue.status" />
                        </li>
                        <li v-if="issues.length === 0" class="px-3 py-6 text-center text-sm text-ink-500">
                            Nothing issued yet.
                        </li>
                    </ul>
                </Card>
            </div>
        </div>

        <!-- Release: the waiver is the only way past a shortage, and it demands a reason -->
        <Modal
            v-model:open="releaseOpen"
            title="Release this job card"
            subtitle="J1: approved artwork, active BOM, tools available, material in stock or waived."
            width="max-w-xl"
        >
            <div v-if="releaseGate.ready" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                All four conditions hold. Releasing reserves stock and puts the first operation on the floor queue.
            </div>

            <div v-else class="space-y-3">
                <div class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900">
                    <p class="font-medium">This job card is not ready.</p>
                    <ul class="mt-1 list-disc pl-4 text-xs">
                        <li v-for="check in checks.filter((c) => !c.ok)" :key="check.label">
                            {{ check.label }} ({{ check.rule }}) — {{ check.detail }}
                        </li>
                    </ul>
                </div>

                <FormField
                    v-if="releaseGate.shortages.length"
                    label="Material waiver reason"
                    rule="J1"
                    hint="Only material shortages can be waived, and only with a reason and the job_card.waive_material permission. Artwork cannot."
                    :error="releaseForm.errors.material_waiver_reason"
                >
                    <textarea v-model="releaseForm.material_waiver_reason" rows="2" class="form-textarea" />
                </FormField>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="releaseForm.processing" @click="release">Release</Button>
            </template>
        </Modal>

        <Modal v-model:open="holdOpen" title="Hold this job card" subtitle="Holding frees the machine slot on the planning board.">
            <FormField label="Hold reason" :error="holdForm.errors.hold_reason" required>
                <textarea v-model="holdForm.hold_reason" rows="3" class="form-textarea" />
            </FormField>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="danger" :loading="holdForm.processing" :disabled="!holdForm.hold_reason" @click="hold">
                    Hold
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
