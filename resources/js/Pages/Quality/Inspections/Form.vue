<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { pcs, titleCase } from '@/plugins/formatting';

const props = defineProps({
    jobCards: { type: Array, default: () => [] },
    defects: { type: Array, default: () => [] },
    plans: { type: Array, default: () => [] },
});

const form = useForm({
    job_card_id: '',
    stage: 'final',
    lot_size: '',
    critical_found: 0,
    major_found: 0,
    minor_found: 0,
    disposition: '',
    disposition_ref: '',
    remarks: '',
    defects: [],
});

/**
 * BR-30 — the plan is a lookup, not a judgement. Resolving it here mirrors what the server
 * will do, so the inspector sees the sample size and the reject number before they count.
 */
const plan = computed(() => {
    const size = Number(form.lot_size) || 0;

    if (size < 1) return null;

    const band = props.plans.find(
        (row) => size >= Number(row.lot_size_from) && size <= Number(row.lot_size_to),
    );

    if (!band) {
        // Below the smallest band ISO 2859-1 inspects the whole lot.
        return { sample_size: size, accept_number: 0, reject_number: 1, whole_lot: true };
    }

    return {
        sample_size: Math.min(Number(band.sample_size), size),
        accept_number: Number(band.accept_number),
        reject_number: Number(band.reject_number),
        whole_lot: false,
    };
});

/** The verdict is computed, never typed (05-workflows §9). */
const verdict = computed(() => {
    if (!plan.value) return null;

    // A single critical defect rejects the lot regardless of the plan — a wrong care symbol
    // on a garment label is a recall, not a statistic.
    if (Number(form.critical_found) >= 1) return 'rejected';

    return Number(form.major_found) >= plan.value.reject_number ? 'rejected' : 'accepted';
});

const dhu = computed(() => {
    if (!plan.value?.sample_size) return 0;

    const total = Number(form.major_found) + Number(form.minor_found) + Number(form.critical_found);

    return Math.round((total / plan.value.sample_size) * 100 * 10000) / 10000;
});

const needsDisposition = computed(() => verdict.value === 'rejected');

function defectCount(defectId) {
    return form.defects.find((row) => row.defect_id === defectId)?.qty ?? 0;
}

/** Tap-to-count: the tablet affordance a defect grid needs on the floor. */
function bump(defect, delta) {
    const existing = form.defects.find((row) => row.defect_id === defect.id);
    const next = Math.max(0, (existing?.qty ?? 0) + delta);

    form.defects = next === 0
        ? form.defects.filter((row) => row.defect_id !== defect.id)
        : existing
            ? form.defects.map((row) => (row.defect_id === defect.id ? { ...row, qty: next } : row))
            : [...form.defects, { defect_id: defect.id, qty: next }];

    syncCountsFromDefects();
}

/** Counting a defect is counting it once — the severity totals follow the grid. */
function syncCountsFromDefects() {
    const totals = { critical: 0, major: 0, minor: 0 };

    for (const row of form.defects) {
        const defect = props.defects.find((d) => d.id === row.defect_id);

        if (defect) totals[defect.severity] += row.qty;
    }

    form.critical_found = totals.critical;
    form.major_found = totals.major;
    form.minor_found = totals.minor;
}

function submit() {
    form.post('/qc-inspections');
}

const severityTone = { critical: 'danger', major: 'warning', minor: 'neutral' };
</script>

<template>
    <AppLayout>
        <Head title="New inspection" />

        <template #title>New inspection</template>
        <template #subtitle>The verdict is computed, never typed (BR-30)</template>

        <template #actions>
            <Button href="/qc-inspections">Cancel</Button>
            <Button
                variant="primary"
                :loading="form.processing"
                :disabled="!form.lot_size || (needsDisposition && !form.disposition)"
                @click="submit"
            >
                Record inspection
            </Button>
        </template>

        <div class="grid max-w-6xl gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card title="What is being inspected">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <FormField label="Job card" :error="form.errors.job_card_id">
                            <SelectInput
                                v-model="form.job_card_id"
                                placeholder="— select —"
                                :options="jobCards"
                                value-key="id"
                                label-key="number"
                            />
                        </FormField>

                        <FormField label="Stage" :error="form.errors.stage" required>
                            <SelectInput
                                v-model="form.stage"
                                :placeholder="null"
                                :options="[
                                    { value: 'incoming', label: 'Incoming' },
                                    { value: 'in_process', label: 'In process' },
                                    { value: 'final', label: 'Final' },
                                    { value: 'pre_dispatch', label: 'Pre-dispatch' },
                                ]"
                            />
                        </FormField>

                        <FormField label="Lot size (pieces)" :error="form.errors.lot_size" required>
                            <TextInput v-model="form.lot_size" type="number" numeric min="1" />
                        </FormField>
                    </div>
                </Card>

                <Card title="Defects found" rule="BR-31" subtitle="Tap to count; the severity totals follow" :padded="false">
                    <div class="divide-y divide-slate-100">
                        <div
                            v-for="defect in defects"
                            :key="defect.id"
                            class="flex items-center gap-3 px-3 py-2"
                            :class="defectCount(defect.id) > 0 && 'bg-amber-50/40'"
                        >
                            <Badge :tone="severityTone[defect.severity]" :label="defect.severity" />

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm text-ink-800">{{ defect.name }}</p>
                                <p class="text-[10px] text-ink-400">{{ defect.code }} · {{ titleCase(defect.process) }}</p>
                            </div>

                            <div class="flex items-center gap-1">
                                <button
                                    class="size-7 rounded-md border border-slate-300 text-ink-700 transition hover:bg-slate-100 disabled:opacity-30"
                                    :disabled="defectCount(defect.id) === 0"
                                    :aria-label="`One fewer ${defect.name}`"
                                    @click="bump(defect, -1)"
                                >
                                    −
                                </button>
                                <span class="w-8 text-center text-sm font-semibold tnum text-ink-900">
                                    {{ defectCount(defect.id) }}
                                </span>
                                <button
                                    class="size-7 rounded-md border border-slate-300 text-ink-700 transition hover:bg-slate-100"
                                    :aria-label="`One more ${defect.name}`"
                                    @click="bump(defect, 1)"
                                >
                                    +
                                </button>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>

            <div class="space-y-4">
                <!-- The plan, resolved live so the inspector knows the numbers before counting. -->
                <Card title="Sampling plan" rule="BR-30" subtitle="ISO 2859-1, Level II, AQL 2.5">
                    <div v-if="plan" class="space-y-3">
                        <dl class="grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-md bg-slate-50 py-2">
                                <dt class="text-[10px] text-ink-500">Sample</dt>
                                <dd class="text-lg font-semibold tnum text-ink-900">{{ pcs(plan.sample_size) }}</dd>
                            </div>
                            <div class="rounded-md bg-emerald-50 py-2">
                                <dt class="text-[10px] text-emerald-700">Accept ≤</dt>
                                <dd class="text-lg font-semibold tnum text-emerald-800">{{ plan.accept_number }}</dd>
                            </div>
                            <div class="rounded-md bg-rose-50 py-2">
                                <dt class="text-[10px] text-rose-700">Reject ≥</dt>
                                <dd class="text-lg font-semibold tnum text-rose-800">{{ plan.reject_number }}</dd>
                            </div>
                        </dl>

                        <p v-if="plan.whole_lot" class="text-xs text-ink-500">
                            Below the smallest band — the whole lot is inspected.
                        </p>

                        <div class="grid grid-cols-3 gap-2 text-center text-sm">
                            <div>
                                <p class="text-[10px] text-ink-500">Critical</p>
                                <p class="font-semibold tnum text-rose-600">{{ form.critical_found }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-ink-500">Major</p>
                                <p class="font-semibold tnum text-ink-900">{{ form.major_found }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-ink-500">DHU</p>
                                <p class="font-semibold tnum text-ink-900">{{ dhu }}</p>
                            </div>
                        </div>

                        <div
                            class="rounded-md px-3 py-2 text-center"
                            :class="verdict === 'rejected' ? 'bg-rose-100 text-rose-900' : 'bg-emerald-100 text-emerald-900'"
                        >
                            <p class="text-[10px] tracking-wider uppercase">Computed verdict</p>
                            <p class="text-lg font-bold">{{ verdict === 'rejected' ? 'REJECTED' : 'ACCEPTED' }}</p>
                        </div>

                        <p v-if="Number(form.critical_found) >= 1" class="text-xs text-rose-700">
                            A single critical defect rejects the lot regardless of the plan.
                        </p>
                    </div>

                    <p v-else class="text-sm text-ink-500">Enter a lot size to resolve the plan.</p>
                </Card>

                <!-- BR-33 / QC2: the database refuses a rejection with no disposition. -->
                <Card v-if="needsDisposition" title="Disposition" rule="BR-33 · QC2">
                    <div class="space-y-3">
                        <p class="rounded-md bg-rose-50 px-3 py-2 text-xs text-rose-900">
                            No lot leaves QC without a disposition. Exactly one of rework, concession,
                            downgrade or scrap.
                        </p>

                        <FormField label="Disposition" :error="form.errors.disposition" required>
                            <SelectInput
                                v-model="form.disposition"
                                placeholder="— select —"
                                :options="[
                                    { value: 'rework', label: 'Rework — back to an operation' },
                                    { value: 'concession', label: 'Concession — customer accepted' },
                                    { value: 'downgrade', label: 'Downgrade — second quality' },
                                    { value: 'scrap', label: 'Scrap — written off' },
                                ]"
                            />
                        </FormField>

                        <FormField
                            v-if="form.disposition === 'concession'"
                            label="Customer approval reference"
                            hint="A concession without evidence is the first thing a brand disputes."
                            :error="form.errors.disposition_ref"
                        >
                            <TextInput v-model="form.disposition_ref" />
                        </FormField>
                    </div>
                </Card>

                <Card title="Remarks">
                    <FormField :error="form.errors.remarks">
                        <textarea v-model="form.remarks" rows="3" class="form-textarea" />
                    </FormField>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
