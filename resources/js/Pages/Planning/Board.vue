<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
import BoardLegend from '@/Components/Planning/BoardLegend.vue';
import StepDuration from '@/Components/Planning/StepDuration.vue';
import { addCalendarDays, date, minutes, pcs, qtyRound, todayIso } from '@/plugins/formatting';
import { dayOf, earliestSlot, groupOpen, readCompact, readFolds, writeCompact, writeFolds } from '@/planning/board';
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

    // A free day carries no colour of its own, so the column's (today, a closed day) shows through.
    return 'text-ink-400';
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

    const where = `${machine.code}, ${weekday(day)} ${dayMonth(day)}`;

    if (!c) return where;
    if (c.is_holiday) return `${where}, holiday`;
    if (!c.load) return `${where}, free, ${minutes(c.available)} available`;

    return `${where}, ${percent(c)} full, ${minutes(c.load)} of ${minutes(c.available)}, ${c.operations} ${c.operations === 1 ? 'step' : 'steps'}`;
}

/*
 * Placing a step. "Schedule" used to open a form with two pickers; the board itself was
 * decoration. Now picking a waiting step turns the board into the chooser: only the machines
 * that can take it stay, each day says how many hours it has free, and one click places it.
 */
const placing = ref(null);
const slot = ref(null);
const slotOpen = ref(false);
/** The slot the board is offering for the step being placed: `{ machineId, parts }`, or null. */
const suggestion = ref(null);
/** True once a slot was asked for and there was none, so the banner can say so. */
const nothingToSuggest = ref(false);

function startPlacing(operation) {
    placing.value = operation;
    slotOpen.value = false;
    suggestion.value = null;
    nothingToSuggest.value = false;

    // Below the widest screens the queue sits above the board: bring what just changed into view.
    nextTick(() => document.querySelector('[data-placing]')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
}

function stopPlacing() {
    placing.value = null;
    suggestion.value = null;
    nothingToSuggest.value = false;
}

function machineCanTake(machine, operation) {
    return !operation?.machine_group_id || machine.machine_group_id === operation.machine_group_id;
}

/** What a cell would be with the step on it, for the placing view. */
function fit(machine, day) {
    const c = cell(machine.id, day);
    const wanted = Number(placing.value?.planned_minutes ?? 0);
    const { free, holiday } = dayOf(c, day);
    // The step ahead of it on the card is still running that day; the scheduler would refuse.
    const early = Boolean(placing.value?.not_before) && day < placing.value.not_before;
    const fits = !holiday && !early && wanted <= free + 0.0001;

    return {
        free,
        holiday,
        early,
        fits,
        // Amber means the same here as when reading: the day ends 85% full or more.
        tight: fits && c.available > 0 && ((c.load + wanted) / c.available) * 100 >= 85,
        suggested: suggestion.value?.machineId === machine.id && suggestion.value.parts.some((part) => part.date === day),
    };
}

function fitTone(f) {
    if (f.holiday || f.early) return 'bg-slate-100 text-ink-500';
    if (!f.fits) return 'bg-rose-100 text-rose-900 ring-1 ring-rose-300 hover:ring-2';
    if (f.tight) return 'bg-amber-100 text-amber-900 ring-1 ring-amber-300 hover:ring-2';

    return 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-300 hover:bg-emerald-100 hover:ring-2';
}

function fitWord(f) {
    if (f.holiday) return 'Holiday';
    if (f.early) return 'too early';
    if (!f.fits) return 'too little';

    return f.tight ? 'tight' : 'free';
}

function describeFit(machine, day) {
    const f = fit(machine, day);
    const where = `${machine.code}, ${weekday(day)} ${dayMonth(day)}`;

    if (f.holiday) return `${where}, holiday`;
    if (f.early) return `${where}, ${minutes(f.free)} available, before the step ahead of it finishes`;

    const verdict = !f.fits ? 'too little for this step' : f.tight ? 'fits, but fills the day' : 'has the hours';

    return `${where}, ${minutes(f.free)} available, ${verdict}${f.suggested ? ', the earliest slot' : ''}`;
}

/** The machines that can take the step being placed, each as its run of days. */
function daysFor(operation) {
    return props.machines
        .filter((machine) => machineCanTake(machine, operation))
        .map((machine) => ({ id: machine.id, days: props.dates.map((day) => dayOf(cell(machine.id, day), day)) }));
}

/** The most any one day in the window could give the step — what the banner quotes when none is enough. */
const longestFree = computed(() => (placing.value
    ? Math.max(0, ...daysFor(placing.value).flatMap((machine) => machine.days.map((day) => day.free)))
    : 0));

const fitsSomewhere = computed(() => !placing.value || Number(placing.value.planned_minutes) <= longestFree.value + 0.0001);

/*
 * "Suggest earliest slot". The planner still confirms: the cell is ringed and brought into
 * view, and the banner offers to place there. Nothing is scheduled by asking.
 */
function suggest(operation) {
    if (placing.value?.id !== operation.id) startPlacing(operation);

    const found = earliestSlot(operation.planned_minutes, daysFor(operation), { notBefore: operation.not_before });

    suggestion.value = found;
    nothingToSuggest.value = !found;

    if (!found) return;

    nextTick(() => {
        const target = document.querySelector(`[data-place-cell="${found.machineId}|${found.parts[0].date}"]`);

        target?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
        target?.focus({ preventScroll: true });
    });
}

const suggestedMachine = computed(() => props.machines.find((machine) => machine.id === suggestion.value?.machineId) ?? null);

function placeSuggested() {
    if (suggestedMachine.value) placeInto(suggestedMachine.value, suggestion.value.parts[0].date);
}

/*
 * The queue and the grid are one job. Pointing at a waiting step lights the machines that can
 * take it; a group's "waiting" count narrows the queue to the steps it is counting.
 */
const hotGroup = ref(null);
const queueGroup = ref(null);

function showWaitingFor(group) {
    queueGroup.value = { id: group.id, name: group.name };

    nextTick(() => {
        const queue = document.querySelector('[data-queue]');

        queue?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        queue?.querySelector('[data-queue-step] button')?.focus({ preventScroll: true });
    });
}

/** Rows at 28 px for a planner who wants the whole floor on one screen; remembered in the browser. */
const compact = ref(readCompact());

watch(compact, (value) => writeCompact(value));

/** Escape leaves placing — unless a panel is open, which answers Escape itself. */
function onKeydown(event) {
    if (event.key === 'Escape' && placing.value && !slotOpen.value && !panelOpen.value) stopPlacing();
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

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
const folds = ref(readFolds());

/** Kept in the browser: a planner who folds the looms on Monday does not want them back on Tuesday. */
function setFolds(next) {
    folds.value = next;
    writeFolds(next);
}

function toggleGroup(group) {
    setFolds({ ...folds.value, groups: { ...folds.value.groups, [group.id]: !isOpen(group) } });
}

/** "Show all machines" and its opposite are a fresh start: they drop the groups set by hand. */
function showAllGroups(all) {
    setFolds({ all, groups: {} });
}

function isOpen(group) {
    if (placing.value) return machineCanTake(group.machines[0], placing.value);

    return groupOpen(group, folds.value);
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
                load: 0,
                available: 0,
            });
        }

        const group = byGroup.get(machine.machine_group_id);
        group.machines.push(machine);

        for (const day of props.dates) {
            const c = cell(machine.id, day);

            if ((c?.load ?? 0) > 0) group.loaded = true;
            group.steps += c?.operations ?? 0;
            group.load += Number(c?.load ?? 0);
            group.available += Number(c?.available ?? 0);
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

/** How full a whole group is over the window — what a folded header says in place of its rows. */
function groupFull(group) {
    if (group.available <= 0) return 'closed this window';

    const pct = (group.load / group.available) * 100;

    return `${group.load > 0 && pct < 0.5 ? '<1' : pcs(pct)}% this window`;
}

/**
 * A day the whole floor is closed, marked down its column. Only the capacity calendar says
 * which days those are — there is no weekly day off recorded anywhere to draw a weekend from.
 */
const closedDays = computed(() => new Set(dayTotals.value.filter((t) => t.is_holiday && props.machines.length > 0).map((t) => t.day)));

function columnTone(day) {
    if (day === today) return 'bg-brand-50/70';
    if (closedDays.value.has(day)) return 'bg-slate-100/80';

    return '';
}

/*
 * "3 machine-days over capacity" used to be a sentence with nowhere to go. It now takes the
 * planner to the first of them: its group opened if it was folded, the cell in view and lit.
 */
const flashed = ref(null);

function showFirstOverCapacity() {
    const over = props.machines
        .flatMap((machine) => props.dates.map((day) => cell(machine.id, day)))
        .find((c) => c?.over_capacity);

    if (!over) return;

    const group = machineGroups.value.find((g) => g.machines.some((m) => m.id === over.machine_id));

    if (group && !isOpen(group)) toggleGroup(group);

    const key = `${over.machine_id}|${over.date}`;

    nextTick(() => {
        const target = document.querySelector(`[data-cell="${key}"]`);

        target?.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' });
        target?.focus({ preventScroll: true });
        flashed.value = key;
        setTimeout(() => { if (flashed.value === key) flashed.value = null; }, 2400);
    });
}

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

/** The queue as shown: every card, or only the steps one machine group is waiting on. */
const queueCards = computed(() => (queueGroup.value
    ? waitingByCard.value
        .map((card) => ({ ...card, steps: card.steps.filter((op) => op.machine_group_id === queueGroup.value.id) }))
        .filter((card) => card.steps.length > 0)
    : waitingByCard.value));

function stepQty(op) {
    return `${op.unit === 'pcs' ? pcs(op.planned_qty) : qtyRound(op.planned_qty)} ${op.unit ?? ''}`.trim();
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

/**
 * Put a step on a machine on a day. When the day has the hours it goes on without a form; a
 * holiday, a day that is too short, or a refusal from the server opens the form with the reason.
 */
function place(operation, machine, day) {
    chosen.value = operation;
    form.defaults({ operation_id: operation.id, machine_id: machine.id, date: day, override_reason: '' });
    form.reset();
    form.clearErrors();

    const { free, holiday } = dayOf(cell(machine.id, day), day);
    const askWhy = () => { slotOpen.value = false; panelOpen.value = true; };

    if (holiday || Number(operation.planned_minutes) > free + 0.0001) {
        askWhy();

        return;
    }

    form.post('/planning/schedule', {
        preserveScroll: true,
        onSuccess: () => { stopPlacing(); chosen.value = null; slotOpen.value = false; },
        onError: askWhy,
    });
}

function placeInto(machine, day) {
    place(placing.value, machine, day);
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
        onSuccess: () => { panelOpen.value = false; chosen.value = null; stopPlacing(); },
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
                class="scroll-mt-16 2xl:sticky 2xl:top-4 2xl:order-2"
                data-queue
                title="Waiting for a slot"
                :subtitle="unscheduledTotal > unscheduled.length
                    ? `The ${unscheduled.length} steps due soonest of ${unscheduledTotal}. Schedule these and the rest appear.`
                    : unscheduledTotal > 0
                        ? `${unscheduledTotal} ${unscheduledTotal === 1 ? 'step' : 'steps'} on ${summary.waiting_cards} ${summary.waiting_cards === 1 ? 'card' : 'cards'}${summary.waiting_late ? `, ${summary.waiting_late} past due` : ''}`
                        : 'Every open step has a machine and a day'"
                :padded="false"
            >
                <!-- Narrowed from a group's "waiting" count on the board, and said so, with the way back. -->
                <p v-if="queueGroup" class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-900" data-queue-filter>
                    Only the steps waiting for {{ queueGroup.name }}.
                    <button type="button" class="min-h-6 font-medium underline underline-offset-2" @click="queueGroup = null">Show all waiting steps</button>
                </p>
                <ul class="divide-y divide-slate-100 text-sm 2xl:block" :class="queueCards.length > 1 ? 'grid md:grid-cols-2 md:divide-y-0' : ''">
                    <li v-for="card in queueCards" :key="card.id" class="min-w-0 px-3 py-2.5">
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
                                class="-mx-1 flex items-center gap-1.5 rounded px-1 py-0.5"
                                :class="placing?.id === op.id ? 'bg-brand-50 ring-1 ring-brand-300' : ''"
                                data-queue-step
                                @mouseenter="hotGroup = op.machine_group_id"
                                @mouseleave="hotGroup = null"
                                @focusin="hotGroup = op.machine_group_id"
                                @focusout="hotGroup = null"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-ink-800">{{ op.sequence_no }} · {{ op.name }}</div>
                                    <div class="text-xs text-ink-500">
                                        {{ op.machine_group ?? 'Any machine' }} · <StepDuration :value="op.planned_minutes" /> · {{ stepQty(op) }}
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
                                <Button
                                    v-if="mayPlan"
                                    size="sm"
                                    variant="ghost"
                                    title="Suggest earliest slot"
                                    :aria-label="`Suggest earliest slot for ${op.name}`"
                                    data-suggest
                                    @click="suggest(op)"
                                >
                                    Suggest
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
                <!--
                    While a step is being placed, the board says so where the eye is: what is
                    being placed, how much is left to place, and how to stop. It stays under the
                    page header while the machines scroll beneath it.
                -->
                <div
                    v-if="placing"
                    class="sticky top-14 z-10 scroll-mt-16 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-sm text-brand-900 shadow-sm"
                    role="status"
                    data-placing
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        <Icon name="planning" size="size-4" class="shrink-0 text-brand-700" />
                        <span>
                            Placing <span class="font-medium">{{ placing.number ?? 'Draft card' }} · {{ placing.sequence_no }} · {{ placing.name }}</span>{{ placing.machine_group ? ` on ${placing.machine_group}` : '' }}
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-xs font-medium ring-1 ring-brand-300 ring-inset" data-placing-remaining>
                            <StepDuration :value="placing.planned_minutes" /> to place
                        </span>
                        <span class="ml-auto flex items-center gap-2">
                            <Button v-if="!suggestion" size="sm" data-suggest-banner @click="suggest(placing)">Suggest earliest slot</Button>
                            <Button size="sm" variant="ghost" title="Escape also cancels" @click="stopPlacing">Cancel</Button>
                        </span>
                    </div>

                    <p v-if="suggestion && suggestedMachine" class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1" data-suggestion>
                        <span>
                            Earliest day with the hours:
                            <span class="font-medium">{{ suggestedMachine.code }}, {{ weekday(suggestion.parts[0].date) }} {{ dayMonth(suggestion.parts[0].date) }}</span>.
                            It is ringed below.
                        </span>
                        <Button size="sm" variant="primary" :loading="form.processing" data-place-suggested @click="placeSuggested">Place there</Button>
                        <Button size="sm" variant="ghost" @click="suggestion = null">Pick another day</Button>
                    </p>
                    <p v-else-if="!fitsSomewhere" class="mt-1.5" data-no-day>
                        No single day in these {{ filters.days }} days has {{ minutes(placing.planned_minutes) }} free — the most is {{ minutes(longestFree) }}.
                        Placing it on one day asks for a reason.
                    </p>
                    <p v-else-if="nothingToSuggest" class="mt-1.5" data-no-day>
                        No day in these {{ filters.days }} days can take it{{ placing.not_before ? ` after the step ahead of it finishes on ${date(placing.not_before)}` : '' }}.
                        <button type="button" class="font-medium underline underline-offset-2" @click="page(1)">Look at later days</button>
                    </p>
                    <p v-else class="mt-1 text-xs">Pick a day on a machine below. Green has the hours; red asks for a reason.</p>
                </div>

                <Card :padded="false">
                    <!-- Where the plan stands, in one line, before any cell. -->
                    <div v-if="!placing" class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-slate-100 px-3 py-2 text-sm">
                        <Badge
                            :tone="scheduled.length > 0 ? 'info' : 'neutral'"
                            :label="`${pcs(scheduled.length)} ${scheduled.length === 1 ? 'step' : 'steps'} planned in these ${filters.days} days`"
                            data-stat-planned
                        />
                        <!-- A day over capacity is somewhere to go, so the figure takes the planner there. -->
                        <button
                            v-if="overCapacityDays > 0"
                            type="button"
                            class="inline-flex min-h-6 items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-800 ring-1 ring-rose-600/20 transition ring-inset hover:bg-rose-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600"
                            data-stat-over
                            @click="showFirstOverCapacity"
                        >
                            <Icon name="warning" size="size-3.5" />
                            {{ pcs(overCapacityDays) }} machine-{{ overCapacityDays === 1 ? 'day' : 'days' }} over capacity — show the first
                        </button>
                        <Badge v-else tone="success" label="No day over capacity" data-stat-over />
                        <span v-if="summary.next_scheduled_on" class="text-ink-600">
                            Next planned after this window
                            <button type="button" class="font-medium text-brand-700 hover:underline" @click="visit({ from: summary.next_scheduled_on })">
                                {{ date(summary.next_scheduled_on) }}
                            </button>
                        </span>
                        <span class="ml-auto flex flex-wrap items-center gap-x-3 gap-y-1">
                            <button
                                v-if="foldedGroups > 0 || folds.all"
                                type="button"
                                class="min-h-6 text-xs font-medium text-brand-700 hover:underline"
                                data-fold-all
                                @click="showAllGroups(foldedGroups > 0)"
                            >
                                {{ foldedGroups > 0 ? `Show all machines (${foldedGroups} ${foldedGroups === 1 ? 'group' : 'groups'} folded)` : 'Fold free groups' }}
                            </button>
                            <Button size="sm" :variant="compact ? 'primary' : 'secondary'" :aria-pressed="compact" data-compact @click="compact = !compact">
                                Compact rows
                            </Button>
                        </span>
                    </div>

                    <!--
                        The grid scrolls inside its own frame, so the days stay above it and the
                        floor's totals below it however far down the machines go.
                    -->
                    <div class="max-h-[calc(100vh-13rem)] min-h-40 overflow-auto" data-board-scroll>
                        <table class="min-w-full border-separate border-spacing-0 text-xs">
                            <thead class="sticky top-0 z-20">
                                <tr>
                                    <th class="sticky left-0 z-10 border-r border-b border-slate-200 bg-slate-50 px-3 py-2 text-left font-semibold text-ink-700">
                                        Machine
                                    </th>
                                    <th
                                        v-for="d in dates"
                                        :key="d"
                                        class="min-w-20 border-b border-slate-200 px-1 py-2 text-center whitespace-nowrap"
                                        :class="d === today ? 'bg-brand-50 text-brand-700' : closedDays.has(d) ? 'bg-slate-100 text-ink-600' : 'bg-slate-50 text-ink-700'"
                                        :aria-current="d === today ? 'date' : undefined"
                                    >
                                        <div class="font-semibold">{{ weekday(d) }}</div>
                                        <div class="font-normal" :class="d === today ? 'text-brand-700' : 'text-ink-600'">{{ dayMonth(d) }}</div>
                                        <!-- Said as well as tinted: the column's colour is not the only sign of what day it is. -->
                                        <div v-if="d === today" class="text-xs font-semibold">Today</div>
                                        <div v-else-if="closedDays.has(d)" class="text-xs">Closed</div>
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <template v-for="group in machineGroups" :key="group.id">
                                    <tr>
                                        <td
                                            :colspan="dates.length + 1"
                                            class="border-b border-slate-100 px-2 py-1"
                                            :class="hotGroup === group.id ? 'bg-brand-50' : 'bg-slate-50'"
                                            :data-group="group.id"
                                            :data-hot="hotGroup === group.id ? '' : undefined"
                                        >
                                            <!-- Pinned to the left edge, so the group's name stays when the days scroll sideways. -->
                                            <div class="sticky left-2 flex w-fit max-w-full flex-wrap items-center gap-1.5">
                                                <button
                                                    type="button"
                                                    class="inline-flex min-h-6 items-center gap-1.5 rounded px-1 text-left text-xs font-semibold text-ink-800 hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                                    :aria-expanded="isOpen(group)"
                                                    :disabled="Boolean(placing)"
                                                    data-group-toggle
                                                    @click="toggleGroup(group)"
                                                >
                                                    <Icon :name="isOpen(group) ? 'down' : 'right'" size="size-3.5" class="text-ink-500" />
                                                    {{ group.name }}
                                                    <span class="font-normal text-ink-600">
                                                        · {{ group.machines.length }} {{ group.machines.length === 1 ? 'machine' : 'machines' }}
                                                        <!-- Folded, the header answers for the rows it hides. -->
                                                        <template v-if="!isOpen(group)"> · {{ groupFull(group) }}<template v-if="group.steps > 0"> · {{ group.steps }} {{ group.steps === 1 ? 'step' : 'steps' }} planned</template></template>
                                                    </span>
                                                </button>
                                                <!-- The cue a planner scans for — and the way to the steps it is counting. -->
                                                <button
                                                    v-if="group.waiting > 0 && !placing"
                                                    type="button"
                                                    class="min-h-6 rounded-full bg-amber-100 px-2 text-xs font-medium text-amber-900 ring-1 ring-amber-300 ring-inset hover:bg-amber-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600"
                                                    :aria-label="`${group.waiting} waiting for ${group.name} — show ${group.waiting === 1 ? 'it' : 'them'} in the queue`"
                                                    data-waiting
                                                    @click="showWaitingFor(group)"
                                                >
                                                    {{ group.waiting }} waiting
                                                </button>
                                                <span v-if="hotGroup === group.id" class="text-xs font-medium text-brand-800">← this step runs here</span>
                                            </div>
                                        </td>
                                    </tr>

                                    <tr v-for="machine in group.machines" v-show="isOpen(group)" :key="machine.id">
                                        <td
                                            class="sticky left-0 z-10 border-r border-b border-slate-100 px-3 whitespace-nowrap"
                                            :class="[hotGroup === group.id ? 'bg-brand-50' : 'bg-white', compact ? 'py-0 align-middle' : 'py-1 align-top']"
                                        >
                                            <span class="font-medium text-ink-800">{{ machine.code }}</span>
                                            <span class="ml-1.5 text-ink-500">{{ Math.round(machine.efficiency_pct) }}% eff</span>
                                        </td>

                                        <td v-for="d in dates" :key="d" class="border-b border-slate-100 p-0.5 align-top" :class="placing ? '' : columnTone(d)">
                                            <!-- Placing: every day is an answer — the hours it has free, and whether that is enough. -->
                                            <button
                                                v-if="placing"
                                                type="button"
                                                class="block w-full rounded px-1 text-center transition focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:outline-none"
                                                :class="[
                                                    fitTone(fit(machine, d)),
                                                    compact ? 'h-7' : 'min-h-10 py-1',
                                                    fit(machine, d).suggested ? 'outline-2 outline-offset-1 outline-brand-600' : '',
                                                ]"
                                                :aria-label="describeFit(machine, d)"
                                                :disabled="form.processing"
                                                :data-place-cell="`${machine.id}|${d}`"
                                                :data-suggested="fit(machine, d).suggested ? '' : undefined"
                                                @click="placeInto(machine, d)"
                                            >
                                                <template v-if="fit(machine, d).holiday">
                                                    <span class="block text-xs">Holiday</span>
                                                </template>
                                                <template v-else-if="compact">
                                                    <span class="block truncate text-xs"><span class="font-semibold tnum">{{ minutes(fit(machine, d).free) }}</span> {{ fit(machine, d).suggested ? 'earliest' : fitWord(fit(machine, d)) }}</span>
                                                </template>
                                                <template v-else>
                                                    <span class="block text-xs font-semibold tnum">{{ minutes(fit(machine, d).free) }}</span>
                                                    <span class="block text-xs">{{ fit(machine, d).suggested ? 'earliest' : fitWord(fit(machine, d)) }}</span>
                                                </template>
                                            </button>

                                            <!-- Reading: the job cards on the machine that day, and how full it is. -->
                                            <button
                                                v-else
                                                type="button"
                                                class="group relative block w-full min-w-16 rounded px-1 pt-1 text-left transition hover:ring-2 hover:ring-brand-500/50 focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:outline-none"
                                                :class="[
                                                    tone(cell(machine.id, d)),
                                                    compact ? 'h-7 !pt-0' : (cell(machine.id, d)?.load ?? 0) > 0 ? 'min-h-10 pb-2.5' : 'min-h-8 pb-1',
                                                    slotOpen && slot?.machine.id === machine.id && slot?.day === d ? 'ring-2 ring-brand-600' : '',
                                                    flashed === `${machine.id}|${d}` ? 'ring-2 ring-rose-600 motion-safe:animate-pulse' : '',
                                                ]"
                                                :aria-label="describe(machine, d)"
                                                :title="mayPlan && !(cell(machine.id, d)?.load > 0) && !cell(machine.id, d)?.is_holiday ? 'Schedule a waiting step here…' : undefined"
                                                :data-cell="`${machine.id}|${d}`"
                                                @click="openSlot(machine, d)"
                                            >
                                                <template v-if="cell(machine.id, d)?.is_holiday">
                                                    <span class="block text-center text-xs">—</span>
                                                </template>
                                                <!-- Compact: one line — how many steps, how full. The names are a click away. -->
                                                <template v-else-if="compact && (cell(machine.id, d)?.load ?? 0) > 0">
                                                    <span class="flex h-full items-center justify-between gap-1 text-xs">
                                                        <span class="truncate">{{ cell(machine.id, d).operations }} {{ cell(machine.id, d).operations === 1 ? 'step' : 'steps' }}</span>
                                                        <span class="font-semibold tnum">{{ (cell(machine.id, d).utilisation_pct ?? 0) > 100 ? '>100%' : `${Math.round(cell(machine.id, d).utilisation_pct ?? 0)}%` }}</span>
                                                    </span>
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
                                                <!-- Free: nothing printed, so nothing to read. The plus appears only when the cell is pointed at. -->
                                                <Icon
                                                    v-else-if="mayPlan"
                                                    name="add"
                                                    size="size-3.5"
                                                    class="mx-auto text-brand-600 opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100"
                                                    :class="compact ? 'mt-1.5' : 'mt-1'"
                                                />
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
                            <tfoot v-if="machines.length > 0 && !placing" class="sticky bottom-0 z-20">
                                <tr>
                                    <td class="sticky left-0 z-10 border-t border-r border-slate-200 bg-slate-50 px-3 py-1.5 font-semibold whitespace-nowrap text-ink-700">
                                        All machines
                                    </td>
                                    <td
                                        v-for="total in dayTotals"
                                        :key="total.day"
                                        class="border-t border-slate-200 px-1 py-1.5 text-center tnum"
                                        :class="total.day === today ? 'bg-brand-50' : closedDays.has(total.day) ? 'bg-slate-100' : 'bg-slate-50'"
                                        :title="`${minutes(total.load)} of ${minutes(total.available)}`"
                                    >
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
                        <BoardLegend :placing="Boolean(placing)" />
                        <span v-if="!placing">A blank day is free. Select a cell for its hours and steps{{ mayPlan ? ', and to schedule into it' : '' }}.</span>
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
                            <StepDuration class="tnum text-xs text-ink-500" :value="op.planned_minutes" />
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
                            <!-- The way out of an empty board is to put something on it. -->
                            <div v-if="mayPlan && unscheduled.length > 0" class="mt-3">
                                <Button variant="primary" size="sm" data-place-first @click="startPlacing(unscheduled[0])">
                                    Place the first waiting step
                                </Button>
                            </div>
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
                    <h3 class="mb-1 text-xs font-semibold text-ink-700">Schedule a waiting step here…</h3>
                    <ul v-if="slotCandidates.length" class="divide-y divide-slate-100 rounded-md border border-slate-200">
                        <li v-for="op in slotCandidates" :key="op.id" class="flex flex-wrap items-center gap-2 px-3 py-2">
                            <span class="font-medium text-ink-900">{{ op.number ?? 'Draft card' }}</span>
                            <span class="text-ink-700">{{ op.name }}</span>
                            <span class="tnum text-xs text-ink-500"><StepDuration :value="op.planned_minutes" /> · due {{ date(op.due_date) }}</span>
                            <Button size="sm" class="ml-auto" :disabled="form.processing" data-schedule-here @click="place(op, slot.machine, slot.day)">Schedule here</Button>
                        </li>
                    </ul>
                    <p v-else class="text-ink-600">No waiting step can run on this machine.</p>
                    <p v-if="slotCandidates.length" class="mt-1 text-xs text-ink-500">A step this day has the hours for goes straight on; a longer one asks for a reason first.</p>
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
