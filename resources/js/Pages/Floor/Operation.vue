<script setup>
import { number } from '@/plugins/formatting';
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import FloorLayout from '@/Layouts/FloorLayout.vue';
import { idempotencyKey, queuedOutputFor, useOfflineQueue } from '@/Composables/useOfflineQueue';
import NotSent from '@/Components/Floor/NotSent.vue';
import { guide, label, refusal, unitLabel, WASTE_TYPES } from '@/floor/dictionary';


const props = defineProps({
    operation: { type: Object, required: true },
    downtimeReasons: { type: Array, default: () => [] },
    shifts: { type: Array, default: () => [] },
    // Whether this person may take a record off the "Not sent" list — a supervisor, not an operator.
    canClearUnsent: { type: Boolean, default: false },
});

const { send, flush, pending, rejected, online, revision } = useOfflineQueue();

/** বাংলা first, English second — the unit every figure on this screen is counted in. */
const unit = computed(() => unitLabel(props.operation.unit));

/** Kept with each record, never posted: what the "Not sent" screen needs to name it. */
const meta = computed(() => ({
    job: props.operation.job_card.number,
    step: props.operation.name,
    unit: props.operation.unit,
}));

const showNotSent = ref(false);

/** What has been booked here and not reached the server yet. Shown in the tiles as pending. */
const queued = computed(() => {
    revision.value;

    return queuedOutputFor(props.operation.id);
});

/** The action being sent, or null. Every button is off while one is in flight. */
const busy = ref(null);

/**
 * One key per intended write, made when its form opens (or, for START, when the screen does)
 * and kept until that write has gone — sent or queued. A second tap sends the same key.
 */
const keys = reactive({ start: idempotencyKey(), log: null, downtime: null, finish: null });

function open(next) {
    keys[next === 'confirm-finish' ? 'finish' : next] ??= idempotencyKey();
    mode.value = next;
}

async function sending(action, work) {
    if (busy.value !== null) return;

    busy.value = action;

    try {
        await work();
    } finally {
        busy.value = null;
    }
}

const mode = ref(null);
const goodQty = ref('');
const wasteQty = ref('');
const inputQty = ref('');
const downtimeReasonId = ref('');
const downtimeMinutes = ref('');
const shiftId = ref('');
const overrideReason = ref('');
const wasteType = ref('');

/*
 * G4 — waste needs a cause, not just a number.
 *
 * Waste was booked as a bare quantity, and `waste_logs` — the table built to hold the cause —
 * was written by nothing at all. A loom losing metres to setup and a loom losing them to a
 * weave defect are different problems with different fixes, and the figure alone cannot tell
 * a supervisor which one they have.
 *
 * The vocabulary is the one the table's own CHECK constraint allows; its words are in the
 * floor dictionary with everything else the terminal says.
 */
const noOutputReason = ref('');
/** `{ tone: 'sent' | 'queued', text }` — a record the server has, or one only this device has. */
const message = ref(null);
const error = ref(null);

/**
 * A refusal is news. The terminal used to treat every non-answer as a wifi blip and retry in
 * silence, so a server-side block looked exactly like a slow network.
 */
function handled(result) {
    // Said from the dictionary by the code the server named it with — never the server's own
    // English sentence, which carries rule numbers and was unreadable to the person it stopped.
    error.value = result?.error ? refusal(result) : null;

    return !result?.error;
}

/**
 * Sent and saved-on-this-device are different facts and are said differently. They used to
 * share one green "Logged", which on a dead link was followed by totals that had not moved.
 */
function report(result, sentText) {
    message.value = result?.queued
        ? { tone: 'queued', text: guide('saved_on_device') }
        : { tone: 'sent', text: sentText };
}

function start() {
    return sending('start', async () => {
        const result = await send(`/api/v1/operations/${props.operation.id}/start`, {}, keys.start, meta.value);

        if (!handled(result)) return;

        keys.start = idempotencyKey();
        report(result, label('started'));
        if (!result?.queued) router.reload();
    });
}

function log() {
    return sending('log', async () => {
        const result = await send(`/api/v1/operations/${props.operation.id}/log`, {
            good_qty: Number(goodQty.value || 0),
            waste_qty: Number(wasteQty.value || 0),
            input_qty: Number(inputQty.value || 0),
            input_override_reason: overrideReason.value || null,
            waste_type: Number(wasteQty.value || 0) > 0 ? wasteType.value || null : null,
        }, keys.log, meta.value);

        if (!handled(result)) return;

        keys.log = null;
        report(result, label('sent'));
        mode.value = null;
        goodQty.value = wasteQty.value = inputQty.value = overrideReason.value = wasteType.value = '';
        if (!result?.queued) router.reload();
    });
}

function finish() {
    return sending('finish', async () => {
        const result = await send(`/api/v1/operations/${props.operation.id}/finish`, {
            no_output_reason: noOutputReason.value || null,
        }, keys.finish, meta.value);

        // Nothing booked: ask why rather than closing a shift's worth of machine time at zero.
        if (result?.error && result.status === 422 && !noOutputReason.value && (result.code ?? 'nothing_booked') === 'nothing_booked') {
            mode.value = 'no-output';
            error.value = null;

            return;
        }

        if (!handled(result)) return;

        keys.finish = null;
        router.visit('/floor/queue');
    });
}

function logDowntime() {
    return sending('downtime', async () => {
        const result = await send(`/api/v1/operations/${props.operation.id}/downtime`, {
            downtime_reason_id: Number(downtimeReasonId.value),
            minutes: Number(downtimeMinutes.value || 0),
            // The shifts were fetched for this screen and then never sent, so every stop landed
            // with no shift against it and no report could break downtime down by one.
            shift_id: shiftId.value ? Number(shiftId.value) : null,
        }, keys.downtime, meta.value);

        if (!handled(result)) return;

        keys.downtime = null;
        report(result, label('downtime_sent'));
        mode.value = null;
        downtimeMinutes.value = '';
    });
}

const sendingNow = ref(false);

async function sendNow() {
    if (sendingNow.value) return;

    sendingNow.value = true;
    await flush();
    sendingNow.value = false;

    if (pending.value === 0) router.reload();
}
</script>

<template>
    <FloorLayout>
        <Head :title="operation.job_card.number ?? 'Operation'" />

        <template #title>{{ operation.job_card.number }}</template>
        <template #subtitle>
            {{ operation.job_card.product_code }} · {{ operation.name }}
            <span v-if="operation.job_card.colourway"> · {{ operation.job_card.colourway }}</span>
        </template>

        <template #actions>
            <div class="flex flex-wrap items-center gap-3">
                <span class="rounded-full px-4 py-2 text-lg font-bold" :class="online ? 'bg-emerald-600' : 'bg-amber-500 text-slate-900'">
                    {{ label(online ? 'online' : 'offline') }}
                </span>

                <!--
                    Always shown when anything is waiting — not only when the browser says it is
                    offline. Wifi up with the server down left records unsent under a green
                    ONLINE pill and nothing on screen to say so.
                -->
                <button
                    v-if="pending > 0"
                    class="min-h-11 rounded-full bg-amber-500 px-5 py-2 text-lg font-bold text-slate-900 disabled:opacity-60"
                    :disabled="sendingNow"
                    @click="sendNow"
                >
                    {{ label('waiting_count', { n: pending }) }} — {{ sendingNow ? '…' : label('send_now') }}
                </button>

                <!--
                    A way back that is not FINISH. Opening the wrong card off the queue used to
                    leave the operator choosing between closing a run they never started and
                    hunting for the browser's back button on a kiosk that has no chrome.
                -->
                <button
                    class="min-h-11 rounded-full bg-white/10 px-5 py-2 text-lg font-bold hover:bg-white/20"
                    @click="router.visit('/floor/queue')"
                >
                    ← {{ label('back_to_queue') }}
                </button>
            </div>
        </template>

        <div class="space-y-5">
            <p
                v-if="message"
                role="status"
                class="rounded-xl px-5 py-4 text-xl font-semibold"
                :class="message.tone === 'queued' ? 'bg-amber-500 text-slate-900' : 'bg-emerald-600'"
            >
                {{ message.text }}
            </p>

            <!-- Refusals are loud on purpose: the operator is standing at a machine. -->
            <p v-if="error" role="alert" class="rounded-xl bg-rose-600 px-5 py-4 text-xl font-semibold">{{ error }}</p>

<button
                v-if="rejected && !showNotSent"
                class="flex min-h-14 w-full flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-500 px-5 py-4 text-left text-lg font-semibold text-slate-900"
                data-open-not-sent
                @click="showNotSent = true; message = null"
            >
                <span>{{ label('not_sent_count', { n: rejected }) }}</span>
                <span class="rounded-full bg-slate-900 px-4 py-1 text-white">{{ label('view') }}</span>
            </button>

            <NotSent v-if="showNotSent" :can-remove="canClearUnsent" :revision="revision" @close="showNotSent = false" />

            <div v-show="!showNotSent" class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <!--
                    Every figure says what it is counted in, and a figure that includes bookings
                    still waiting on this device says how much of it is waiting.
                -->
                <div class="rounded-2xl bg-white/5 p-4">
                    <p class="text-sm text-slate-300">{{ label('planned') }}</p>
                    <p class="text-3xl font-bold tnum">{{ number(operation.planned_qty) }}</p>
                    <p class="text-base text-slate-300">{{ unit }}</p>
                </div>
                <div class="rounded-2xl bg-white/5 p-4">
                    <p class="text-sm text-slate-300">{{ label('input') }}</p>
                    <p class="text-3xl font-bold tnum">{{ number(Number(operation.input_qty) + queued.input) }}</p>
                    <p class="text-base text-slate-300">{{ unit }}</p>
                    <p v-if="queued.input > 0" class="mt-1 text-base font-semibold text-amber-400">+{{ number(queued.input) }} {{ label('waiting_marker') }}</p>
                </div>
                <div class="rounded-2xl bg-white/5 p-4">
                    <p class="text-sm text-slate-300">{{ label('good') }}</p>
                    <p class="text-3xl font-bold tnum text-emerald-400">{{ number(Number(operation.good_qty) + queued.good) }}</p>
                    <p class="text-base text-slate-300">{{ unit }}</p>
                    <p v-if="queued.good > 0" class="mt-1 text-base font-semibold text-amber-400">+{{ number(queued.good) }} {{ label('waiting_marker') }}</p>
                </div>
                <div class="rounded-2xl bg-white/5 p-4">
                    <p class="text-sm text-slate-300">{{ label('waste') }}</p>
                    <p class="text-3xl font-bold tnum text-rose-400">{{ number(Number(operation.waste_qty) + queued.waste) }}</p>
                    <p class="text-base text-slate-300">{{ unit }}</p>
                    <p v-if="queued.waste > 0" class="mt-1 text-base font-semibold text-amber-400">+{{ number(queued.waste) }} {{ label('waiting_marker') }}</p>
                </div>
            </div>

            <!-- Gate 1, on the floor: the operator can see which artwork version this run prints -->
            <p v-show="!showNotSent" class="rounded-xl bg-white/5 px-4 py-3 text-lg text-slate-300">
                {{ label('artwork') }}: <span class="font-bold text-white">{{ operation.job_card.artwork }}</span>
            </p>

            <!-- Four buttons. That is the whole vocabulary. -->
            <template v-if="showNotSent" />

            <div v-else-if="!mode" class="grid grid-cols-2 gap-3">
                <!--
                    A step that is already running has nothing to start. The greyed-out START
                    that sat here looked like a fault and gave no reason; this says what is true.
                -->
                <p
                    v-if="operation.status === 'in_progress'"
                    class="flex min-h-24 flex-col items-center justify-center rounded-2xl border-2 border-emerald-400 px-3 text-center"
                    data-running
                >
                    <span class="text-2xl font-bold text-emerald-300">{{ label('running') }}</span>
                    <span class="text-base text-slate-300">{{ guide('running_hint') }}</span>
                </p>
                <button v-else class="floor-btn bg-emerald-500" :disabled="busy !== null" @click="start">
                    {{ busy === 'start' ? '…' : label('start') }}
                </button>
                <button class="floor-btn bg-sky-500" :disabled="busy !== null" @click="open('log')">{{ label('output') }}</button>
                <button class="floor-btn bg-amber-500 text-slate-900" :disabled="busy !== null" @click="open('downtime')">{{ label('downtime') }}</button>
                <button class="floor-btn bg-slate-600" :disabled="busy !== null" @click="open('confirm-finish')">{{ label('finish') }}</button>
            </div>

            <!--
                FINISH closes the step for good — the next step is readied and the terminal has
                no undo — and it used to fire on one tap, in a grid beside OUTPUT and DOWNTIME.
                The job and what has been booked are read back before it is done.
            -->
            <div v-else-if="mode === 'confirm-finish'" class="space-y-4">
                <div class="rounded-2xl bg-amber-500 px-5 py-5 text-slate-900">
                    <p class="text-2xl font-bold">{{ label('finish_question') }}</p>
                    <p class="mt-2 text-xl font-semibold">
                        {{ operation.job_card.number }} · {{ operation.name }}
                    </p>
                    <p class="mt-2 text-xl">
                        {{ label('good') }}: <span class="font-bold tnum">{{ number(Number(operation.good_qty) + queued.good) }} {{ unit }}</span>
                        &nbsp;·&nbsp;
                        {{ label('waste') }}: <span class="font-bold tnum">{{ number(Number(operation.waste_qty) + queued.waste) }} {{ unit }}</span>
                    </p>
                    <p class="mt-3 text-lg">{{ guide('finish_consequence') }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <button class="floor-btn bg-slate-600" @click="mode = null">{{ label('finish_no') }}</button>
                    <button class="floor-btn bg-emerald-500 disabled:opacity-30" :disabled="busy !== null" @click="finish">
                        {{ busy === 'finish' ? '…' : label('finish_yes') }}
                    </button>
                </div>
            </div>

            <div v-else-if="mode === 'log'" class="space-y-4">
                <div>
                    <label for="floor-input" class="mb-1 block text-xl">{{ label('input_received') }} ({{ unit }})</label>
                    <input id="floor-input" v-model="inputQty" inputmode="decimal" class="w-full rounded-xl bg-white/10 px-5 py-5 text-4xl tnum text-white">
                </div>
                <div>
                    <label for="floor-good" class="mb-1 block text-xl">{{ label('good') }} ({{ unit }})</label>
                    <input id="floor-good" v-model="goodQty" inputmode="decimal" class="w-full rounded-xl bg-white/10 px-5 py-5 text-4xl tnum text-white">
                </div>
                <div>
                    <label for="floor-waste" class="mb-1 block text-xl">{{ label('waste') }} ({{ unit }})</label>
                    <input id="floor-waste" v-model="wasteQty" inputmode="decimal" class="w-full rounded-xl bg-white/10 px-5 py-5 text-4xl tnum text-white">
                </div>

                <!--
                    Asked only when there is waste to explain, so an ordinary booking is still
                    two numbers and SAVE. Native picker for the same reason as the machine and
                    downtime lists: gloves, no keyboard.
                -->
                <div v-if="Number(wasteQty) > 0">
                    <label for="floor-waste-type" class="mb-1 block text-xl">{{ label('waste_what') }}</label>
                    <select id="floor-waste-type" v-model="wasteType" class="w-full rounded-xl bg-white/10 px-5 py-5 text-2xl text-white">
                        <option value="" class="text-slate-900">— {{ label('choose_reason') }} —</option>
                        <option v-for="type in WASTE_TYPES" :key="type" :value="type" class="text-slate-900">
                            {{ label(`waste_${type}`) }}
                        </option>
                    </select>
                </div>
                <p class="text-lg text-slate-300">{{ guide('allowance', { qty: number(operation.remaining_allowance), unit }) }}</p>

                <!-- Input beyond the plan is allowed, but it has to be explained (J3). -->
                <div v-if="Number(inputQty) > Number(operation.planned_qty) * 1.03">
                    <label for="floor-override" class="mb-1 block text-xl">
                        {{ guide('why_more', { qty: number(operation.planned_qty), unit }) }}
                    </label>
                    <input id="floor-override" v-model="overrideReason" class="w-full rounded-xl bg-white/10 px-5 py-5 text-2xl text-white">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <button class="floor-btn bg-slate-600" @click="mode = null">{{ label('cancel') }}</button>
                    <button
                        class="floor-btn bg-emerald-500 disabled:opacity-30"
                        :disabled="busy !== null || (Number(wasteQty) > 0 && !wasteType)"
                        @click="log"
                    >
                        {{ busy === 'log' ? '…' : label('save') }}
                    </button>
                </div>
            </div>

            <div v-else-if="mode === 'no-output'" class="space-y-4">
                <p class="rounded-xl bg-amber-500 px-5 py-4 text-xl font-semibold text-slate-900">
                    {{ guide('nothing_booked') }}
                </p>

                <div>
                    <label for="floor-no-output" class="mb-1 block text-xl">{{ label('reason') }}</label>
                    <input id="floor-no-output" v-model="noOutputReason" class="w-full rounded-xl bg-white/10 px-5 py-5 text-2xl text-white">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <button class="floor-btn bg-slate-600" @click="mode = null">{{ label('cancel') }}</button>
                    <button class="floor-btn bg-emerald-500 disabled:opacity-30" :disabled="!noOutputReason || busy !== null" @click="finish">
                        {{ busy === 'finish' ? '…' : label('finish') }}
                    </button>
                </div>
            </div>

            <div v-else-if="mode === 'downtime'" class="space-y-4">
                <!--
                    Native picker on purpose: the floor terminal is touched with gloves and has
                    no keyboard, so the OS wheel beats a filter box the operator cannot type in.
                -->
                <select v-model="downtimeReasonId" :aria-label="label('reason')" class="w-full rounded-xl bg-white/10 px-5 py-5 text-2xl text-white">
                    <option value="" class="text-slate-900">— {{ label('choose_reason') }} —</option>
                    <option v-for="reason in downtimeReasons" :key="reason.id" :value="reason.id" class="text-slate-900">
                        {{ reason.name }}
                    </option>
                </select>
                <input
                    v-model="downtimeMinutes"
                    inputmode="numeric"
                    :placeholder="label('minutes')"
                    :aria-label="label('minutes')"
                    class="w-full rounded-xl bg-white/10 px-5 py-5 text-4xl tnum text-white"
                >
                <select v-if="shifts.length" v-model="shiftId" :aria-label="label('choose_shift')" class="w-full rounded-xl bg-white/10 px-5 py-4 text-2xl text-white">
                    <option value="" class="text-slate-900">— {{ label('choose_shift') }} —</option>
                    <option v-for="shift in shifts" :key="shift.id" :value="shift.id" class="text-slate-900">
                        {{ shift.name }}
                    </option>
                </select>
                <div class="grid grid-cols-2 gap-3">
                    <button class="floor-btn bg-slate-600" @click="mode = null">{{ label('cancel') }}</button>
                    <button
                        class="floor-btn bg-amber-500 text-slate-900 disabled:opacity-30"
                        :disabled="!downtimeReasonId || !downtimeMinutes || busy !== null"
                        @click="logDowntime"
                    >
                        {{ busy === 'downtime' ? '…' : label('save') }}
                    </button>
                </div>
            </div>
        </div>
    </FloorLayout>
</template>
