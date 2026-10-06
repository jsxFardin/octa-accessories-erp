<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Icon from '@/Components/Ui/Icon.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import SlideOver from '@/Components/Ui/SlideOver.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { addCalendarDays, date, minutes, pcs, qty, todayIso } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useGuardedAction } from '@/composables/useGuardedAction';

const props = defineProps({
    machines: { type: Array, default: () => [] },
    dates: { type: Array, default: () => [] },
    cells: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    groups: { type: Array, default: () => [] },
    unscheduled: { type: Array, default: () => [] },
    scheduled: { type: Array, default: () => [] },
    // How many steps are waiting in all; `unscheduled` holds the first fifty of them.
    unscheduledTotal: { type: Number, default: 0 },
    summary: { type: Object, default: () => ({}) },
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
 * over-committed machine before they add to it, not after the delivery slips. An empty day is
 * left white and silent: a grid of "0%" said nothing twenty-three times a row.
 */
function tone(cell) {
    if (!cell || cell.is_holiday) return 'bg-slate-100 text-ink-400';
    if (cell.over_capacity) return 'bg-rose-100 text-rose-900 ring-1 ring-rose-300';
    if (cell.utilisation_pct >= 85) return 'bg-amber-100 text-amber-900';
    if (cell.utilisation_pct > 0) return 'bg-emerald-50 text-emerald-900';

    return 'bg-white text-ink-400';
}

function barTone(cell) {
    if (cell?.over_capacity) return 'bg-rose-500';
    if ((cell?.utilisation_pct ?? 0) >= 85) return 'bg-amber-500';

    return 'bg-emerald-500';
}

function visit(changes) {
    router.get('/planning', { ...props.filters, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
}

function shiftWindow(days) {
    visit({ days });
}

/*
 * The window could be made longer but never moved: the board opened on today and there was no
 * way to look at next month, or back at last week. The server has always taken `from`.
 */
const today = todayIso();
const onToday = computed(() => props.filters.from === today);

function page(direction) {
    visit({ from: addCalendarDays(props.filters.from, direction * Number(props.filters.days)) });
}

function weekday(value) {
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'short' });
}

/** Day then month, as everywhere else in the app. The header used to print the ISO tail, "10-05". */
function dayMonth(value) {
    const [, month, day] = value.split('-');

    return `${day}/${month}`;
}

function percent(c) {
    if (!c || c.is_holiday) return 'Holiday';

    return (c.utilisation_pct ?? 0) > 100 ? 'over 100%' : `${Math.round(c.utilisation_pct ?? 0)}%`;
}

/** What a cell is, in words — for a screen reader, and for anyone without a mouse to hover with. */
function describe(machine, day) {
    const c = cell(machine.id, day);

    if (!c) return `${machine.code}, ${date(day)}`;
    if (c.is_holiday) return `${machine.code}, ${date(day)}: holiday`;
    if (!c.load) return `${machine.code}, ${date(day)}: free, ${minutes(c.available)} available`;

    return `${machine.code}, ${date(day)}: ${percent(c)} full, ${minutes(c.load)} of ${minutes(c.available)}, ${c.operations} ${c.operations === 1 ? 'step' : 'steps'}`;
}

/*
 * Placing a step. "Schedule" used to open a form with two pickers; the board itself was
 * decoration. Now picking a waiting step turns the board into the chooser: only the machines
 * that can take it stay, each day says how many hours it has free, and one click places it.
 */
const placing = ref(null);
const slot = ref(null);
const slotOpen = ref(false);

function startPlacing(operation) {
    placing.value = operation;
    slotOpen.value = false;
}

function stopPlacing() {
    placing.value = null;
}

function machineCanTake(machine, operation) {
    return !operation?.machine_group_id || machine.machine_group_id === operation.machine_group_id;
}

/** What a cell would be with the step on it, for the placing view. */
function fit(machine, day) {
    const c = cell(machine.id, day);
    const wanted = Number(placing.value?.planned_minutes ?? 0);

    if (!c) return { free: 0, fits: false, holiday: false };

    const free = Math.max(0, c.available - c.load);

    return { free, fits: !c.is_holiday && wanted <= free + 0.0001, holiday: c.is_holiday };
}

/** The job cards on a machine on a day — what a planner actually reads off a board. */
const stepsByCell = computed(() => {
    const map = {};

    for (const op of props.scheduled) {
        const key = `${op.machine_id}|${String(op.scheduled_start).slice(0, 10)}`;
        (map[key] ??= []).push(op);
    }

    return map;
});

function stepsIn(machineId, day) {
    return stepsByCell.value[`${machineId}|${day}`] ?? [];
}

/*
 * Machines by group, because a routing names a group: the planner looking for somewhere to put
 * a cutting step wants the cutting machines together, with the number waiting for them.
 *
 * A group with nothing on it and nothing waiting for it folds to one line. Twenty-three rows
 * of free machines were the wall the board hid behind.
 */
const toggled = ref(new Map());
const showAll = ref(false);

function toggleGroup(group) {
    const next = new Map(toggled.value);
    next.set(group.id, !isOpen(group));
    toggled.value = next;
}

function isOpen(group) {
    if (placing.value) return machineCanTake(group.machines[0], placing.value);
    if (toggled.value.has(group.id)) return toggled.value.get(group.id);

    return showAll.value || group.loaded || group.waiting > 0;
}

const machineGroups = computed(() => {
    const byGroup = new Map();

    for (const machine of props.machines) {
        if (!byGroup.has(machine.machine_group_id)) {
            byGroup.set(machine.machine_group_id, {
                id: machine.machine_group_id,
                code: machine.group_code,
                name: machine.group_name,
                machines: [],
                waiting: props.unscheduled.filter((op) => op.machine_group_id === machine.machine_group_id).length,
                loaded: false,
                steps: 0,
            });
        }

        const group = byGroup.get(machine.machine_group_id);
        group.machines.push(machine);

        for (const day of props.dates) {
            const c = cell(machine.id, day);

            if ((c?.load ?? 0) > 0) group.loaded = true;
            group.steps += c?.operations ?? 0;
        }
    }

    const groups = [...byGroup.values()];

    // While placing, only the groups that can take the step are on the board at all.
    return placing.value ? groups.filter((g) => machineCanTake(g.machines[0], placing.value)) : groups;
});

const foldedGroups = computed(() => machineGroups.value.filter((g) => !isOpen(g)).length);

/** One row for the whole floor: which day of the window is the tight one. */
const dayTotals = computed(() => props.dates.map((day) => {
    let load = 0;
    let available = 0;

    for (const machine of props.machines) {
        const c = cell(machine.id, day);
        load += Number(c?.load ?? 0);
        available += Number(c?.available ?? 0);
    }

    const pct = available > 0 ? (load / available) * 100 : 0;

    return { day, load, available, utilisation_pct: pct, over_capacity: load > available + 0.0001, is_holiday: available === 0 };
}));

const overCapacityDays = computed(() => props.cells.filter((c) => c.over_capacity).length);

/*
 * Waiting work by job card. Six flat rows reading "(unnumbered) Plate making 1,050,000 …" hid
 * that they were two cards; a planner schedules a card's steps in order, so the card is the unit.
 */
const waitingByCard = computed(() => {
    const cards = new Map();

    for (const op of props.unscheduled) {
        if (!cards.has(op.job_card_id)) {
            cards.set(op.job_card_id, {
                id: op.job_card_id,
                number: op.number,
                status: op.job_card_status,
                due_date: op.due_date,
                late: op.due_date && op.due_date < today,
                steps: [],
            });
        }

        cards.get(op.job_card_id).steps.push(op);
    }

    return [...cards.values()];
});

function stepQty(op) {
    return `${op.unit === 'pcs' ? pcs(op.planned_qty) : qty(op.planned_qty)} ${op.unit ?? ''}`.trim();
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

/** Place by click: the step fits, so it goes on without a form; a refusal opens the form with its reason. */
function placeInto(machine, day) {
    const operation = placing.value;

    chosen.value = operation;
    form.defaults({ operation_id: operation.id, machine_id: machine.id, date: day, override_reason: '' });
    form.reset();
    form.clearErrors();

    const { fits, holiday } = fit(machine, day);

    if (!fits || holiday) {
        panelOpen.value = true;

        return;
    }

    form.post('/planning/schedule', {
        preserveScroll: true,
        onSuccess: () => { placing.value = null; chosen.value = null; },
        onError: () => { panelOpen.value = true; },
    });
}

function plan(operation, slot = null) {
    chosen.value = operation;
    form.defaults({
        operation_id: operation.id,
        machine_id: slot?.machine.id ?? '',
        date: slot?.day ?? props.dates[0] ?? '',
        override_reason: '',
    });
    form.reset();
    form.clearErrors();
    slotOpen.value = false;
    panelOpen.value = true;
}

/*
 * A cell is a place, and a place can be chosen. The cells were coloured boxes with their
 * detail in a hover tooltip — nothing to click, nothing at all on a touch screen. Selecting
 * one now shows what the tooltip did, the steps planned there, and the steps that could go there.
 */
function openSlot(machine, day) {
    slot.value = { machine, day };
    slotOpen.value = true;
}

const slotCell = computed(() => (slot.value ? cell(slot.value.machine.id, slot.value.day) : null));

const slotSteps = computed(() => (slot.value
    ? props.scheduled.filter((op) => op.machine_id === slot.value.machine.id
        && String(op.scheduled_start).slice(0, 10) === slot.value.day)
    : []));

/** Waiting steps this machine may take: the routing names a group, or names none. */
const slotCandidates = computed(() => (slot.value
    ? props.unscheduled.filter((op) => !op.machine_group_id || op.machine_group_id === slot.value.machine.machine_group_id)
    : []));

const { busy: takingOff, run: guarded } = useGuardedAction();

function submit() {
    form.post('/planning/schedule', {
        preserveScroll: true,
        onSuccess: () => { panelOpen.value = false; chosen.value = null; placing.value = null; },
    });
}

function unschedule(operation) {
    // Not asked about — it is undone by scheduling the step again — but it cannot be fired twice.
    guarded(`off-${operation.id}`, null, (done) => router.post(
        '/planning/unschedule',
        { operation_id: operation.id },
        { preserveScroll: true, ...done },
    ));
}
</script>

<template>
    <AppLayout>
        <Head title="Planning board" />

        <template #title>Planning board</template>
        <template #subtitle>How full each machine is, day by day. Available minutes allow for planned downtime and machine efficiency.</template>

        <template #actions>
            <div class="flex items-center gap-1" role="group" aria-label="Move the window">
                <Button size="sm" aria-label="Earlier days" data-earlier @click="page(-1)">←</Button>
                <Button size="sm" :disabled="onToday" data-today @click="visit({ from: undefined })">Today</Button>
                <Button size="sm" aria-label="Later days" data-later @click="page(1)">→</Button>
            </div>

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

        <!--
            The work first, then the capacity. The waiting steps were under a grid that scrolled
            for a screen and a half; a planner's job is to get them onto it, so they sit beside it.
        -->
        <div class="grid gap-4 2xl:grid-cols-[minmax(0,1fr)_21rem] 2xl:items-start">
            <!-- The queue of work with no machine and no day, the card as the unit. -->
            <Card
                class="2xl:sticky 2xl:top-4 2xl:order-2"
                title="Waiting for a slot"
                :subtitle="unscheduledTotal > unscheduled.length
                    ? `The ${unscheduled.length} steps due soonest of ${unscheduledTotal}. Schedule these and the rest appear.`
                    : unscheduledTotal > 0
                        ? `${unscheduledTotal} ${unscheduledTotal === 1 ? 'step' : 'steps'} on ${summary.waiting_cards} ${summary.waiting_cards === 1 ? 'card' : 'cards'}${summary.waiting_late ? `, ${summary.waiting_late} past due` : ''}`
                        : 'Every open step has a machine and a day'"
                :padded="false"
            >
                <ul class="divide-y divide-slate-100 text-sm 2xl:block" :class="waitingByCard.length > 1 ? 'grid md:grid-cols-2 md:divide-y-0' : ''">
                    <li v-for="card in waitingByCard" :key="card.id" class="min-w-0 px-3 py-2.5">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <Link :href="`/job-cards/${card.id}`" class="doc-link-quiet font-medium">{{ card.number ?? 'Draft card' }}</Link>
                            <Badge v-if="card.status === 'draft'" tone="neutral" label="Draft" />
                            <span class="text-xs" :class="card.late ? 'font-medium text-rose-700' : 'text-ink-500'">
                                due {{ date(card.due_date) }}{{ card.late ? ' · past due' : '' }}
                            </span>
                        </div>
                        <ul class="mt-1.5 space-y-1">
                            <li
                                v-for="op in card.steps"
                                :key="op.id"
                                class="-mx-1 flex items-center gap-2 rounded px-1 py-0.5"
                                :class="placing?.id === op.id ? 'bg-brand-50 ring-1 ring-brand-300' : ''"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-ink-800">{{ op.sequence_no }} · {{ op.name }}</div>
                                    <div class="truncate text-xs text-ink-500">
                                        {{ op.machine_group ?? 'Any machine' }} · {{ minutes(op.planned_minutes) }} · {{ stepQty(op) }}
                                    </div>
                                </div>
                                <Button
                                    v-if="mayPlan"
                                    size="sm"
                                    :variant="placing?.id === op.id ? 'primary' : 'secondary'"
                                    data-place
                                    @click="placing?.id === op.id ? stopPlacing() : startPlacing(op)"
                                >
                                    {{ placing?.id === op.id ? 'Placing…' : 'Place' }}
                                </Button>
                            </li>
                        </ul>
                    </li>
                    <li v-if="unscheduled.length === 0" class="px-3 py-8 text-center text-ink-500 md:col-span-2">
                        Everything open is scheduled.
                    </li>
                </ul>
            </Card>

            <div class="min-w-0 space-y-4 2xl:order-1">
                <Card :padded="false">
                    <!--
                        While a step is being placed, the board says so where the eye is: what is
                        being placed, what it needs, and how to stop.
                    -->
                    <div v-if="placing" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-brand-200 bg-brand-50 px-3 py-2 text-sm text-brand-900" data-placing>
                        <Icon name="planning" size="size-4" class="shrink-0 text-brand-700" />
                        <span>
                            Placing <span class="font-medium">{{ placing.number ?? 'Draft card' }} · {{ placing.sequence_no }} · {{ placing.name }}</span>
                            ({{ minutes(placing.planned_minutes) }}{{ placing.machine_group ? `, ${placing.machine_group}` : '' }}).
                            Pick a day on a machine below; a green day has the hours.
                        </span>
                        <Button size="sm" variant="ghost" class="ml-auto" @click="stopPlacing">Cancel</Button>
                    </div>

                    <!-- Where the plan stands, in one line, before any cell. -->
                    <div v-else class="flex flex-wrap items-center gap-x-5 gap-y-1 border-b border-slate-100 px-3 py-2 text-sm">
                        <span class="text-ink-600">
                            <span class="font-medium text-ink-900">{{ pcs(scheduled.length) }}</span>&nbsp;{{ scheduled.length === 1 ? 'step' : 'steps' }} planned in these {{ filters.days }} days
                        </span>
                        <span v-if="overCapacityDays > 0" class="text-rose-700">
                            <span class="font-medium">{{ pcs(overCapacityDays) }}</span> machine-{{ overCapacityDays === 1 ? 'day' : 'days' }} over capacity
                        </span>
                        <span v-else class="text-ink-600">No day over capacity</span>
                        <span v-if="summary.next_scheduled_on" class="text-ink-600">
                            Next planned after this window
                            <button type="button" class="font-medium text-brand-700 hover:underline" @click="visit({ from: summary.next_scheduled_on })">
                                {{ date(summary.next_scheduled_on) }}
                            </button>
                        </span>
                        <button
                            v-if="foldedGroups > 0 || showAll"
                            type="button"
                            class="ml-auto text-xs font-medium text-brand-700 hover:underline"
                            @click="showAll = !showAll; toggled = new Map()"
                        >
                            {{ showAll ? 'Fold free groups' : `Show all machines (${foldedGroups} free ${foldedGroups === 1 ? 'group' : 'groups'} folded)` }}
                        </button>
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
                                        class="px-1 py-2 text-center whitespace-nowrap"
                                        :class="d === today ? 'text-brand-700' : 'text-ink-700'"
                                    >
                                        <div class="font-semibold">{{ weekday(d) }}</div>
                                        <div class="font-normal" :class="d === today ? 'text-brand-700' : 'text-ink-600'">{{ dayMonth(d) }}</div>
                                        <!-- Today, marked once in the header rather than a tinted column fighting the load colours. -->
                                        <span v-if="d === today" class="mx-auto mt-0.5 block h-0.5 w-6 rounded bg-brand-500" aria-hidden="true" />
                                    </th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-100">
                                <template v-for="group in machineGroups" :key="group.id">
                                    <tr class="bg-slate-50/70">
                                        <td :colspan="dates.length + 1" class="px-2 py-1">
                                            <button
                                                type="button"
                                                class="inline-flex min-h-6 max-w-full items-center gap-1.5 rounded px-1 text-left text-xs font-semibold text-ink-800 hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                                :aria-expanded="isOpen(group)"
                                                :disabled="Boolean(placing)"
                                                @click="toggleGroup(group)"
                                            >
                                                <Icon :name="isOpen(group) ? 'down' : 'right'" size="size-3.5" class="text-ink-500" />
                                                {{ group.name }}
                                                <span class="font-normal text-ink-500">
                                                    · {{ group.machines.length }} {{ group.machines.length === 1 ? 'machine' : 'machines' }}
                                                    <template v-if="!isOpen(group)"> · {{ group.loaded ? `${group.steps} ${group.steps === 1 ? 'step' : 'steps'} planned` : 'all free' }}</template>
                                                </span>
                                                <!-- The cue a planner scans for: this group has work waiting for it. -->
                                                <span v-if="group.waiting > 0" class="rounded-full bg-amber-100 px-1.5 text-xs font-medium text-amber-800">
                                                    {{ group.waiting }} waiting
                                                </span>
                                            </button>
                                        </td>
                                    </tr>

                                    <tr v-for="machine in group.machines" v-show="isOpen(group)" :key="machine.id">
                                        <td class="sticky left-0 z-10 bg-white px-3 py-1 whitespace-nowrap align-top">
                                            <span class="font-medium text-ink-800">{{ machine.code }}</span>
                                            <span class="ml-1.5 text-ink-500">{{ Math.round(machine.efficiency_pct) }}% eff</span>
                                        </td>

                                        <td v-for="d in dates" :key="d" class="p-0.5 align-top">
                                            <!-- Placing: every day is an answer — the hours it has free, and whether that is enough. -->
                                            <button
                                                v-if="placing"
                                                type="button"
                                                class="block min-h-10 w-full min-w-16 rounded px-1 py-1 text-center transition focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:outline-none"
                                                :class="fit(machine, d).holiday
                                                    ? 'bg-slate-100 text-ink-400'
                                                    : fit(machine, d).fits
                                                        ? 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-300 hover:bg-emerald-100 hover:ring-2'
                                                        : 'bg-rose-50 text-rose-800 ring-1 ring-rose-200 hover:ring-2 hover:ring-rose-300'"
                                                :aria-label="`${machine.code}, ${date(d)}: ${fit(machine, d).holiday ? 'holiday' : `${minutes(fit(machine, d).free)} free${fit(machine, d).fits ? '' : ', not enough'}`}`"
                                                :disabled="form.processing"
                                                data-place-cell
                                                @click="placeInto(machine, d)"
                                            >
                                                <template v-if="fit(machine, d).holiday">
                                                    <span class="block text-xs">Holiday</span>
                                                </template>
                                                <template v-else>
                                                    <span class="block text-xs font-semibold tnum">{{ minutes(fit(machine, d).free) }}</span>
                                                    <span class="block text-xs">{{ fit(machine, d).fits ? 'free' : 'too little' }}</span>
                                                </template>
                                            </button>

                                            <!-- Reading: the job cards on the machine that day, and how full it is. -->
                                            <button
                                                v-else
                                                type="button"
                                                class="relative block w-full min-w-16 rounded px-1 pt-1 text-left transition hover:ring-2 hover:ring-brand-500/50 focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:outline-none"
                                                :class="[
                                                    tone(cell(machine.id, d)),
                                                    (cell(machine.id, d)?.load ?? 0) > 0 ? 'min-h-10 pb-2.5' : 'min-h-8 pb-1',
                                                    slotOpen && slot?.machine.id === machine.id && slot?.day === d ? 'ring-2 ring-brand-600' : '',
                                                ]"
                                                :aria-label="describe(machine, d)"
                                                data-cell
                                                @click="openSlot(machine, d)"
                                            >
                                                <template v-if="cell(machine.id, d)?.is_holiday">
                                                    <span class="block text-center text-xs">—</span>
                                                </template>
                                                <template v-else-if="(cell(machine.id, d)?.load ?? 0) > 0">
                                                    <span
                                                        v-for="op in stepsIn(machine.id, d).slice(0, 2)"
                                                        :key="op.id"
                                                        class="block truncate rounded bg-white/70 px-1 text-xs leading-5 text-ink-800"
                                                        :title="`${op.number ?? 'Draft card'} · ${op.name} · ${minutes(op.planned_minutes)}`"
                                                    >
                                                        <span class="font-medium">{{ op.number ? op.number.replace(/^JC-\d{2}-0*/, '#') : 'Draft' }}</span> {{ op.name }}
                                                    </span>
                                                    <span v-if="(cell(machine.id, d)?.operations ?? 0) > Math.min(2, stepsIn(machine.id, d).length)" class="block px-1 text-xs text-ink-600">
                                                        +{{ (cell(machine.id, d)?.operations ?? 0) - Math.min(2, stepsIn(machine.id, d).length) }} more
                                                    </span>
                                                    <!-- Capped: over 100 becomes a flat "over"; the exact figure is in the panel the cell opens. -->
                                                    <span class="absolute right-1.5 bottom-0.5 text-xs font-semibold tnum">
                                                        {{ (cell(machine.id, d)?.utilisation_pct ?? 0) > 100 ? '>100%' : `${Math.round(cell(machine.id, d)?.utilisation_pct ?? 0)}%` }}
                                                    </span>
                                                    <span class="absolute inset-x-1.5 bottom-1 h-1 w-1/2 rounded-full bg-black/5" aria-hidden="true">
                                                        <span class="block h-full rounded-full" :class="barTone(cell(machine.id, d))" :style="{ width: `${Math.min(100, cell(machine.id, d)?.utilisation_pct ?? 0)}%` }" />
                                                    </span>
                                                </template>
                                                <span v-else class="block text-center text-xs text-slate-300" aria-hidden="true">·</span>
                                            </button>
                                        </td>
                                    </tr>
                                </template>

                                <tr v-if="machineGroups.length === 0">
                                    <td :colspan="dates.length + 1" class="px-3 py-10 text-center text-ink-500">
                                        {{ placing ? `No active machine in ${placing.machine_group} — the step cannot be placed until one is.` : 'No active machines in this group.' }}
                                    </td>
                                </tr>
                            </tbody>

                            <!-- The floor as a whole, so the tight day shows without reading every row. -->
                            <tfoot v-if="machines.length > 0 && !placing">
                                <tr class="border-t border-slate-200 bg-slate-50">
                                    <td class="sticky left-0 z-10 bg-slate-50 px-3 py-1.5 font-semibold whitespace-nowrap text-ink-700">
                                        All machines
                                    </td>
                                    <td v-for="total in dayTotals" :key="total.day" class="px-1 py-1.5 text-center tnum" :title="`${minutes(total.load)} of ${minutes(total.available)}`">
                                        <span
                                            class="text-xs font-semibold"
                                            :class="total.over_capacity ? 'text-rose-700' : total.utilisation_pct >= 85 ? 'text-amber-700' : total.load > 0 ? 'text-ink-800' : 'text-ink-400'"
                                        >
                                            {{ total.is_holiday ? '—' : total.load > 0 && total.utilisation_pct < 0.5 ? '<1%' : `${Math.round(total.utilisation_pct)}%` }}
                                        </span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- The colour ramp, named, under the grid it describes. -->
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-100 px-3 py-1.5 text-xs text-ink-600">
                        <template v-if="placing">
                            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-emerald-50 ring-1 ring-emerald-300" /> Has the hours</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-rose-50 ring-1 ring-rose-200" /> Not enough — placing there asks for a reason</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-slate-100 ring-1 ring-slate-200" /> Holiday</span>
                        </template>
                        <template v-else>
                            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-emerald-50 ring-1 ring-emerald-200" /> Loaded</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-amber-100 ring-1 ring-amber-300" /> 85%+ full</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-rose-100 ring-1 ring-rose-300" /> Over capacity</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-slate-100 ring-1 ring-slate-200" /> Holiday</span>
                            <span>A blank day is free. Select a cell for its minutes and steps{{ mayPlan ? ', and to schedule into it' : '' }}.</span>
                        </template>
                    </div>
                </Card>

                <!--
                    What is on the board, and the way back off it. A placement with no way to
                    correct it is a placement a planner will not make.
                -->
                <Card title="Scheduled in this window" subtitle="Move a step by placing it again; take it off to free the slot" :padded="false">
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="op in scheduled" :key="op.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                            <span class="text-ink-700">{{ weekday(op.scheduled_start.slice(0, 10)) }} {{ date(op.scheduled_start) }}</span>
                            <span class="font-medium text-ink-800">{{ op.machine }}</span>
                            <Link :href="`/job-cards/${op.job_card_id}`" class="doc-link-quiet">{{ op.number ?? 'Draft card' }}</Link>
                            <span class="text-ink-700">{{ op.sequence_no }} · {{ op.name }}</span>
                            <span class="tnum text-xs text-ink-500">{{ minutes(op.planned_minutes) }}</span>
                            <span class="ml-auto text-xs text-ink-500">due {{ date(op.due_date) }}</span>
                            <Button v-if="mayPlan" size="sm" variant="ghost" :loading="takingOff === `off-${op.id}`" :disabled="takingOff !== null" @click="unschedule(op)">Take off</Button>
                        </li>
                        <li v-if="scheduled.length === 0" class="px-3 py-6 text-center text-sm text-ink-600">
                            <template v-if="summary.scheduled_total > 0">
                                Nothing is planned in these {{ filters.days }} days.
                                <template v-if="summary.next_scheduled_on">
                                    The next planned step is on
                                    <button type="button" class="font-medium text-brand-700 hover:underline" @click="visit({ from: summary.next_scheduled_on })">{{ date(summary.next_scheduled_on) }}</button>.
                                </template>
                                <template v-else-if="summary.previous_scheduled_on">
                                    The last planned step was on
                                    <button type="button" class="font-medium text-brand-700 hover:underline" @click="visit({ from: summary.previous_scheduled_on })">{{ date(summary.previous_scheduled_on) }}</button>.
                                </template>
                            </template>
                            <template v-else-if="unscheduledTotal > 0">
                                Nothing is planned yet. Place a waiting step and it appears here and on the board.
                            </template>
                            <template v-else>Nothing is planned, and nothing is waiting.</template>
                        </li>
                    </ul>
                </Card>
            </div>
        </div>

        <SlideOver
            v-model:open="slotOpen"
            :title="slot ? `${slot.machine.code} on ${weekday(slot.day)} ${date(slot.day)}` : ''"
            :subtitle="slot ? `${slot.machine.name} · ${slot.machine.group_name}` : null"
        >
            <div v-if="slot" class="space-y-4 text-sm" data-slot>
                <p v-if="slotCell?.is_holiday" class="rounded-md bg-amber-50 px-3 py-2 text-amber-900">
                    This day is a holiday for this machine. Scheduling into it needs a reason.
                </p>
                <dl v-else-if="slotCell" class="grid grid-cols-3 gap-2">
                    <div><dt class="text-xs text-ink-500">Planned</dt><dd class="font-medium tnum">{{ minutes(slotCell.load) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Available</dt><dd class="font-medium tnum">{{ minutes(slotCell.available) }}</dd></div>
                    <div>
                        <dt class="text-xs text-ink-500">Full</dt>
                        <dd class="font-medium tnum" :class="slotCell.over_capacity ? 'text-rose-700' : 'text-ink-900'">
                            {{ Math.round(slotCell.utilisation_pct ?? 0) }}%
                        </dd>
                    </div>
                </dl>

                <section>
                    <h3 class="mb-1 text-xs font-semibold text-ink-700">Planned here</h3>
                    <ul v-if="slotSteps.length" class="divide-y divide-slate-100 rounded-md border border-slate-200">
                        <li v-for="op in slotSteps" :key="op.id" class="flex flex-wrap items-center gap-2 px-3 py-2">
                            <Link :href="`/job-cards/${op.job_card_id}`" class="doc-link-quiet">{{ op.number ?? 'Draft card' }}</Link>
                            <span class="text-ink-700">{{ op.name }}</span>
                            <span class="tnum text-xs text-ink-500">{{ minutes(op.planned_minutes) }}</span>
                            <Button
                                v-if="mayPlan" size="sm" variant="ghost" class="ml-auto"
                                :loading="takingOff === `off-${op.id}`" :disabled="takingOff !== null"
                                @click="unschedule(op)"
                            >Take off</Button>
                        </li>
                    </ul>
                    <p v-else class="text-ink-600">
                        {{ slotCell?.operations ? 'The steps here have already started, so they cannot be moved from the board.' : 'Nothing is planned here.' }}
                    </p>
                </section>

                <section v-if="mayPlan">
                    <h3 class="mb-1 text-xs font-semibold text-ink-700">Schedule a waiting step here</h3>
                    <ul v-if="slotCandidates.length" class="divide-y divide-slate-100 rounded-md border border-slate-200">
                        <li v-for="op in slotCandidates" :key="op.id" class="flex flex-wrap items-center gap-2 px-3 py-2">
                            <span class="font-medium text-ink-900">{{ op.number ?? 'Draft card' }}</span>
                            <span class="text-ink-700">{{ op.name }}</span>
                            <span class="tnum text-xs text-ink-500">{{ minutes(op.planned_minutes) }} · due {{ date(op.due_date) }}</span>
                            <Button size="sm" class="ml-auto" data-schedule-here @click="plan(op, slot)">Schedule here</Button>
                        </li>
                    </ul>
                    <p v-else class="text-ink-600">No waiting step can run on this machine.</p>
                </section>
            </div>

            <template #footer>
                <Button @click="slotOpen = false">Close</Button>
            </template>
        </SlideOver>

        <SlideOver
            v-model:open="panelOpen"
            :title="chosen ? `Schedule ${chosen.name}` : 'Schedule'"
            :subtitle="chosen ? `${chosen.number ?? 'Draft card'} · step ${chosen.sequence_no} · ${minutes(chosen.planned_minutes)}` : null"
            :dirty="form.isDirty"
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
                        <span class="tnum text-ink-800">{{ minutes(preview.load) }} of {{ minutes(preview.available) }}</span>
                    </div>
                    <div class="mt-1 flex items-center justify-between">
                        <span class="text-ink-600">After this step</span>
                        <span class="tnum font-medium" :class="preview.wouldOverrun ? 'text-rose-700' : 'text-emerald-700'">
                            {{ minutes(preview.after) }} of {{ minutes(preview.available) }}
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
