<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import SlideOver from '@/Components/Ui/SlideOver.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, pcs } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    machines: { type: Array, default: () => [] },
    dates: { type: Array, default: () => [] },
    cells: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    groups: { type: Array, default: () => [] },
    unscheduled: { type: Array, default: () => [] },
    scheduled: { type: Array, default: () => [] },
});

const cellIndex = computed(() => {
    const map = {};

    for (const cell of props.cells) {
        map[`${cell.machine_id}|${cell.date}`] = cell;
    }

    return map;
});

function cell(machineId, date) {
    return cellIndex.value[`${machineId}|${date}`];
}

/**
 * BR-27 — the board blocks scheduling past 100%. The colour ramp exists so a planner sees the
 * over-committed machine before they add to it, not after the delivery slips.
 */
function tone(cell) {
    if (!cell || cell.is_holiday) return 'bg-slate-100 text-ink-400';
    if (cell.over_capacity) return 'bg-rose-100 text-rose-900 ring-1 ring-rose-300';
    if (cell.utilisation_pct >= 85) return 'bg-amber-100 text-amber-900';
    if (cell.utilisation_pct > 0) return 'bg-emerald-50 text-emerald-900';

    return 'bg-white text-slate-300';
}

function shiftWindow(days) {
    router.get('/planning', { ...props.filters, days }, { preserveState: true, replace: true });
}

function weekday(value) {
    return new Date(value).toLocaleDateString('en-GB', { weekday: 'short' });
}

/*
 * Scheduling. The board used to render capacity and offer no way to fill it: `scheduled_start`
 * and the planner's choice of machine were columns nothing in the application ever wrote, so
 * every cell was empty by construction and the job card's own "schedule its operations to plan
 * it" pointed at a screen that could not.
 */
const mayPlan = can('production_plan.update');
const panelOpen = ref(false);
const chosen = ref(null);

const form = useForm({ operation_id: null, machine_id: '', date: '', override_reason: '' });

/** A routing names a machine *group*; a press cannot weave, so the list is filtered to it. */
const eligibleMachines = computed(() => props.machines
    .filter((m) => !chosen.value?.machine_group_id || m.machine_group_id === chosen.value.machine_group_id)
    .map((m) => ({ value: m.id, label: m.code, hint: m.name })));

/** The cell the planner is about to add to, so the panel can show what is left before they commit. */
const preview = computed(() => {
    if (!form.machine_id || !form.date) return null;

    const c = cell(form.machine_id, form.date);

    if (!c) return null;

    const wanted = Number(chosen.value?.planned_minutes ?? 0);

    return { ...c, wanted, after: c.load + wanted, wouldOverrun: c.load + wanted > c.available + 0.0001 };
});

function plan(operation) {
    chosen.value = operation;
    form.defaults({ operation_id: operation.id, machine_id: '', date: props.dates[0] ?? '', override_reason: '' });
    form.reset();
    form.clearErrors();
    panelOpen.value = true;
}

function submit() {
    form.post('/planning/schedule', {
        preserveScroll: true,
        onSuccess: () => { panelOpen.value = false; chosen.value = null; },
    });
}

function unschedule(operation) {
    router.post('/planning/unschedule', { operation_id: operation.id }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head title="Planning board" />

        <template #title>Planning board</template>
        <template #subtitle>Machine × day utilisation — available minutes are discounted by planned downtime and machine efficiency</template>

        <template #actions>
            <div class="w-36">
                <SelectInput
                    :model-value="filters.group ?? ''"
                    placeholder="All groups"
                    :options="groups"
                    value-key="id"
                    label-key="name"
                    @update:model-value="router.get('/planning', { ...filters, group: $event || undefined }, { preserveState: true, replace: true })"
                />
            </div>

            <div class="w-28">
                <SelectInput
                    :model-value="filters.days"
                    :placeholder="null"
                    :options="[7, 10, 14, 21].map((n) => ({ value: n, label: `${n} days` }))"
                    @update:model-value="shiftWindow($event)"
                />
            </div>
        </template>

        <div class="space-y-4">
            <Card :padded="false">
                <!-- The colour ramp, named. Four tones with no key made the board a guess. -->
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-slate-100 px-3 py-2 text-[11px] text-ink-600">
                    <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-emerald-50 ring-1 ring-emerald-200" /> Loaded</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-amber-100 ring-1 ring-amber-300" /> 85%+ full</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-rose-100 ring-1 ring-rose-300" /> Over capacity</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-slate-100 ring-1 ring-slate-200" /> Holiday</span>
                    <span class="text-ink-400">Hover a cell for minutes and operations.</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50">
                                <th class="sticky left-0 z-10 bg-slate-50 px-3 py-2 text-left font-semibold text-ink-700">
                                    Machine
                                </th>
                                <th
                                    v-for="d in dates"
                                    :key="d"
                                    class="px-1 py-2 text-center font-semibold whitespace-nowrap text-ink-700"
                                >
                                    <div>{{ weekday(d) }}</div>
                                    <div class="font-normal text-ink-400">{{ d.slice(5) }}</div>
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="machine in machines" :key="machine.id">
                                <td class="sticky left-0 z-10 bg-white px-3 py-1.5 whitespace-nowrap">
                                    <div class="font-medium text-ink-800">{{ machine.code }}</div>
                                    <div class="text-[10px] text-ink-500">
                                        {{ machine.group_code }} · {{ Math.round(machine.efficiency_pct) }}% eff
                                    </div>
                                </td>

                                <td v-for="d in dates" :key="d" class="p-0.5">
                                    <div
                                        class="rounded px-1 py-1.5 text-center tnum"
                                        :class="tone(cell(machine.id, d))"
                                        :title="cell(machine.id, d)
                                            ? `${Math.round(cell(machine.id, d).utilisation_pct ?? 0)}% — ${cell(machine.id, d).load} of ${cell(machine.id, d).available} min · ${cell(machine.id, d).operations} ${cell(machine.id, d).operations === 1 ? 'op' : 'ops'}`
                                            : ''"
                                    >
                                        <!-- Capped: 5781% in the same visual language as 11% reads as noise, not
                                             as an alarm. Over 100 becomes a flat "over"; the exact figure stays
                                             in the tooltip. -->
                                        <div class="text-[11px] font-semibold">
                                            {{ cell(machine.id, d)?.is_holiday
                                                ? '—'
                                                : (cell(machine.id, d)?.utilisation_pct ?? 0) > 100
                                                    ? '>100%'
                                                    : `${Math.round(cell(machine.id, d)?.utilisation_pct ?? 0)}%` }}
                                        </div>
                                        <div class="text-[9px] opacity-70">
                                            {{ cell(machine.id, d)?.operations || '' }}
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="machines.length === 0">
                                <td :colspan="dates.length + 1" class="px-3 py-10 text-center text-ink-500">
                                    No active machines in this group.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card title="Unscheduled operations" subtitle="Waiting for a machine and a slot" :padded="false">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="op in unscheduled" :key="op.id" class="flex flex-wrap items-center gap-3 px-3 py-2">
                        <Link :href="`/job-cards/${op.job_card_id}`" class="doc-link-quiet">
                            {{ op.number ?? '(unnumbered)' }}
                        </Link>
                        <span class="text-ink-700">{{ op.name }}</span>
                        <span class="tnum text-xs text-ink-500">{{ pcs(op.planned_qty) }}</span>
                        <span class="text-xs text-ink-500">{{ op.machine_group ?? 'any machine' }}</span>
                        <span class="tnum text-xs text-ink-500">{{ Math.round(op.planned_minutes) }} min</span>
                        <span class="ml-auto text-xs text-ink-500">due {{ date(op.due_date) }}</span>
                        <Button v-if="mayPlan" size="sm" variant="secondary" @click="plan(op)">Schedule</Button>
                    </li>
                    <li v-if="unscheduled.length === 0" class="px-3 py-6 text-center text-ink-500">
                        Everything open is scheduled.
                    </li>
                </ul>
            </Card>

            <!--
                What is on the board, and the way back off it. A placement with no way to
                correct it is a placement a planner will not make.
            -->
            <Card title="Scheduled in this window" subtitle="Move an operation by scheduling it again; take it off to free the slot" :padded="false">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="op in scheduled" :key="op.id" class="flex flex-wrap items-center gap-3 px-3 py-2">
                        <Link :href="`/job-cards/${op.job_card_id}`" class="doc-link-quiet">
                            {{ op.number ?? '(unnumbered)' }}
                        </Link>
                        <span class="text-ink-700">{{ op.name }}</span>
                        <span class="font-medium text-ink-800">{{ op.machine }}</span>
                        <span class="text-xs text-ink-500">{{ date(op.scheduled_start) }}</span>
                        <span class="tnum text-xs text-ink-500">{{ Math.round(op.planned_minutes) }} min</span>
                        <span class="ml-auto text-xs text-ink-500">due {{ date(op.due_date) }}</span>
                        <template v-if="mayPlan">
                            <Button size="sm" variant="ghost" @click="unschedule(op)">Take off</Button>
                        </template>
                    </li>
                    <li v-if="scheduled.length === 0" class="px-3 py-6 text-center text-ink-500">
                        Nothing is scheduled in this window.
                    </li>
                </ul>
            </Card>
        </div>

        <SlideOver
            v-model:open="panelOpen"
            :title="chosen ? `Schedule ${chosen.name}` : 'Schedule'"
            :subtitle="chosen ? `${chosen.number ?? '(unnumbered)'} · step ${chosen.sequence_no} · ${Math.round(chosen.planned_minutes)} minutes` : null"
        >
            <div class="space-y-4">
                <FormField
                    label="Machine"
                    :hint="chosen?.machine_group ? `Limited to ${chosen.machine_group} — the routing names the group, and a press cannot weave.` : 'This step names no machine group, so any machine may take it.'"
                    :error="form.errors.machine_id"
                >
                    <SelectInput v-model="form.machine_id" :options="eligibleMachines" hint-key="hint" placeholder="Pick a machine" />
                </FormField>

                <FormField label="Day" :error="form.errors.scheduled_start ?? form.errors.date">
                    <DateInput v-model="form.date" />
                </FormField>

                <!--
                    BR-27 made visible before the commit, not after the refusal. The planner can
                    see the day fill up as they pick, which is the whole point of a board.
                -->
                <div v-if="preview" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-ink-600">That day now</span>
                        <span class="tnum text-ink-800">{{ Math.round(preview.load) }} of {{ Math.round(preview.available) }} min</span>
                    </div>
                    <div class="mt-1 flex items-center justify-between">
                        <span class="text-ink-600">After this step</span>
                        <span class="tnum font-medium" :class="preview.wouldOverrun ? 'text-rose-700' : 'text-emerald-700'">
                            {{ Math.round(preview.after) }} of {{ Math.round(preview.available) }} min
                        </span>
                    </div>
                    <p v-if="preview.is_holiday" class="mt-2 text-amber-700">
                        This day is a holiday for that machine. Scheduling into it needs a reason.
                    </p>
                    <p v-else-if="preview.wouldOverrun" class="mt-2 text-rose-700">
                        This would take the machine past its available minutes. Pick another day or machine, or record why it is being over-committed.
                    </p>
                </div>

                <FormField
                    label="Override reason"
                    hint="Only needed to schedule past capacity or into a holiday. It is kept on the audit trail."
                    :error="form.errors.override_reason"
                >
                    <TextInput v-model="form.override_reason" placeholder="Why this machine is being over-committed" />
                </FormField>

                <p v-if="form.errors.operation_id" class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    {{ form.errors.operation_id }}
                </p>
            </div>

            <template #footer>
                <Button variant="ghost" @click="panelOpen = false">Cancel</Button>
                <Button variant="primary" :disabled="!form.machine_id || !form.date || form.processing" @click="submit">
                    {{ form.processing ? 'Scheduling…' : 'Schedule' }}
                </Button>
            </template>
        </SlideOver>
    </AppLayout>
</template>
