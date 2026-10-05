<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import FloorLayout from '@/Layouts/FloorLayout.vue';
import { idempotencyKey, queuedOutputFor, useOfflineQueue } from '@/Composables/useOfflineQueue';


const props = defineProps({
    operation: { type: Object, required: true },
    downtimeReasons: { type: Array, default: () => [] },
    shifts: { type: Array, default: () => [] },
});

const { send, flush, pending, rejected, online, revision } = useOfflineQueue();

/** বাংলা first, English second — the unit every figure on this screen is counted in. */
const UNITS = { m: 'মিটার · m', pcs: 'পিস · pcs' };
const unit = computed(() => UNITS[props.operation.unit] ?? props.operation.unit ?? '');

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
 * The vocabulary is the one the table's own CHECK constraint allows.
 */
const WASTE_TYPES = [
    { value: 'setup', label: 'সেটআপ · Setup' },
    { value: 'shade', label: 'শেড · Shade' },
    { value: 'weave_defect', label: 'বুনন ত্রুটি · Weave defect' },
    { value: 'print_defect', label: 'প্রিন্ট ত্রুটি · Print defect' },
    { value: 'cutting', label: 'কাটিং · Cutting' },
    { value: 'edge_trim', label: 'ধার · Edge trim' },
    { value: 'damaged', label: 'ক্ষতিগ্রস্ত · Damaged' },
    { value: 'expired', label: 'মেয়াদোত্তীর্ণ · Expired' },
    { value: 'other', label: 'অন্যান্য · Other' },
];
const noOutputReason = ref('');
/** `{ tone: 'sent' | 'queued', text }` — a record the server has, or one only this device has. */
const message = ref(null);
const error = ref(null);

/**
 * A refusal is news. The terminal used to treat every non-answer as a wifi blip and retry in
 * silence, so a server-side block looked exactly like a slow network.
 */
function handled(result) {
    error.value = result?.error ? result.message : null;

    return !result?.error;
}

/**
 * Sent and saved-on-this-device are different facts and are said differently. They used to
 * share one green "Logged", which on a dead link was followed by totals that had not moved.
 */
function report(result, sentText) {
    message.value = result?.queued
        ? { tone: 'queued', text: 'এই ডিভাইসে সেভ হয়েছে — সংযোগ ফিরলে পাঠানো হবে · Saved on this device — it will be sent when the connection is back' }
        : { tone: 'sent', text: sentText };
}

function start() {
    return sending('start', async () => {
        const result = await send(`/api/v1/operations/${props.operation.id}/start`, {}, keys.start);

        if (!handled(result)) return;

        keys.start = idempotencyKey();
        report(result, 'শুরু হয়েছে · Started');
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
        }, keys.log);

        if (!handled(result)) return;

        keys.log = null;
        report(result, 'রেকর্ড হয়েছে · Sent');
        mode.value = null;
        goodQty.value = wasteQty.value = inputQty.value = overrideReason.value = wasteType.value = '';
        if (!result?.queued) router.reload();
    });
}

function finish() {
    return sending('finish', async () => {
        const result = await send(`/api/v1/operations/${props.operation.id}/finish`, {
            no_output_reason: noOutputReason.value || null,
        }, keys.finish);

        // Nothing booked: ask why rather than closing a shift's worth of machine time at zero.
        if (result?.error && result.status === 422 && !noOutputReason.value) {
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
        }, keys.downtime);

        if (!handled(result)) return;

        keys.downtime = null;
        report(result, 'ডাউনটাইম রেকর্ড · Downtime sent');
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
                    {{ online ? 'ONLINE' : 'OFFLINE' }}
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
                    {{ pending }}টি অপেক্ষায় · {{ pending }} WAITING — {{ sendingNow ? '…' : 'এখন পাঠান · SEND NOW' }}
                </button>

                <!--
                    A way back that is not FINISH. Opening the wrong card off the queue used to
                    leave the operator choosing between closing a run they never started and
                    hunting for the browser's back button on a kiosk that has no chrome.
                -->
                <button
                    class="rounded-full bg-white/10 px-5 py-2 text-lg font-bold hover:bg-white/20"
                    @click="router.visit('/floor/queue')"
                >
                    ← কাজের তালিকা · QUEUE
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

            <p v-if="rejected" class="rounded-xl bg-amber-500 px-5 py-4 text-lg font-semibold text-slate-900">
                {{ rejected }}টি রেকর্ড পাঠানো যায়নি — সুপারভাইজারকে জানান · {{ rejected }} record(s) not sent — call your supervisor
            </p>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <!--
                    Every figure says what it is counted in, and a figure that includes bookings
                    still waiting on this device says how much of it is waiting.
                -->
                <div class="rounded-2xl bg-white/5 p-4">
                    <p class="text-sm text-slate-300">পরিকল্পিত · Planned</p>
                    <p class="text-3xl font-bold tnum">{{ Number(operation.planned_qty).toLocaleString() }}</p>
                    <p class="text-base text-slate-300">{{ unit }}</p>
                </div>
                <div class="rounded-2xl bg-white/5 p-4">
                    <p class="text-sm text-slate-300">ইনপুট · Input</p>
                    <p class="text-3xl font-bold tnum">{{ (Number(operation.input_qty) + queued.input).toLocaleString() }}</p>
                    <p class="text-base text-slate-300">{{ unit }}</p>
                    <p v-if="queued.input > 0" class="mt-1 text-base font-semibold text-amber-400">+{{ queued.input.toLocaleString() }} অপেক্ষায় · waiting</p>
                </div>
                <div class="rounded-2xl bg-white/5 p-4">
                    <p class="text-sm text-slate-300">ভালো · Good</p>
                    <p class="text-3xl font-bold tnum text-emerald-400">{{ (Number(operation.good_qty) + queued.good).toLocaleString() }}</p>
                    <p class="text-base text-slate-300">{{ unit }}</p>
                    <p v-if="queued.good > 0" class="mt-1 text-base font-semibold text-amber-400">+{{ queued.good.toLocaleString() }} অপেক্ষায় · waiting</p>
                </div>
                <div class="rounded-2xl bg-white/5 p-4">
                    <p class="text-sm text-slate-300">নষ্ট · Waste</p>
                    <p class="text-3xl font-bold tnum text-rose-400">{{ (Number(operation.waste_qty) + queued.waste).toLocaleString() }}</p>
                    <p class="text-base text-slate-300">{{ unit }}</p>
                    <p v-if="queued.waste > 0" class="mt-1 text-base font-semibold text-amber-400">+{{ queued.waste.toLocaleString() }} অপেক্ষায় · waiting</p>
                </div>
            </div>

            <!-- Gate 1, on the floor: the operator can see which artwork version this run prints -->
            <p class="rounded-xl bg-white/5 px-4 py-3 text-lg text-slate-300">
                আর্টওয়ার্ক · Artwork: <span class="font-bold text-white">{{ operation.job_card.artwork }}</span>
            </p>

            <!-- Four buttons. That is the whole vocabulary. -->
            <div v-if="!mode" class="grid grid-cols-2 gap-3">
                <button
                    class="floor-btn bg-emerald-500 disabled:opacity-30"
                    :disabled="operation.status === 'in_progress' || busy !== null"
                    @click="start"
                >
                    {{ busy === 'start' ? '…' : 'শুরু · START' }}
                </button>
                <button class="floor-btn bg-sky-500" :disabled="busy !== null" @click="open('log')">আউটপুট · OUTPUT</button>
                <button class="floor-btn bg-amber-500 text-slate-900" :disabled="busy !== null" @click="open('downtime')">ডাউনটাইম · DOWNTIME</button>
                <button class="floor-btn bg-slate-600" :disabled="busy !== null" @click="open('confirm-finish')">শেষ · FINISH</button>
            </div>

            <!--
                FINISH closes the step for good — the next step is readied and the terminal has
                no undo — and it used to fire on one tap, in a grid beside OUTPUT and DOWNTIME.
                The job and what has been booked are read back before it is done.
            -->
            <div v-else-if="mode === 'confirm-finish'" class="space-y-4">
                <div class="rounded-2xl bg-amber-500 px-5 py-5 text-slate-900">
                    <p class="text-2xl font-bold">এই ধাপ শেষ করবেন? · Finish this step?</p>
                    <p class="mt-2 text-xl font-semibold">
                        {{ operation.job_card.number }} · {{ operation.name }}
                    </p>
                    <p class="mt-2 text-xl">
                        ভালো · Good: <span class="font-bold tnum">{{ (Number(operation.good_qty) + queued.good).toLocaleString() }} {{ operation.unit }}</span>
                        &nbsp;·&nbsp;
                        নষ্ট · Waste: <span class="font-bold tnum">{{ (Number(operation.waste_qty) + queued.waste).toLocaleString() }} {{ operation.unit }}</span>
                    </p>
                    <p class="mt-3 text-lg">
                        শেষ করার পর এই টার্মিনাল থেকে এই ধাপে আর কিছু বুক করা যাবে না।
                        · After this, nothing more can be booked on this step from the terminal.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <button class="floor-btn bg-slate-600" @click="mode = null">না, ফিরে যান · NO, GO BACK</button>
                    <button class="floor-btn bg-emerald-500 disabled:opacity-30" :disabled="busy !== null" @click="finish">
                        {{ busy === 'finish' ? '…' : 'হ্যাঁ, শেষ · YES, FINISH' }}
                    </button>
                </div>
            </div>

            <div v-else-if="mode === 'log'" class="space-y-4">
                <div>
                    <label for="floor-input" class="mb-1 block text-xl">ইনপুট · Input received ({{ unit }})</label>
                    <input id="floor-input" v-model="inputQty" inputmode="decimal" class="w-full rounded-xl bg-white/10 px-5 py-5 text-4xl tnum text-white">
                </div>
                <div>
                    <label for="floor-good" class="mb-1 block text-xl">ভালো · Good ({{ unit }})</label>
                    <input id="floor-good" v-model="goodQty" inputmode="decimal" class="w-full rounded-xl bg-white/10 px-5 py-5 text-4xl tnum text-white">
                </div>
                <div>
                    <label for="floor-waste" class="mb-1 block text-xl">নষ্ট · Waste ({{ unit }})</label>
                    <input id="floor-waste" v-model="wasteQty" inputmode="decimal" class="w-full rounded-xl bg-white/10 px-5 py-5 text-4xl tnum text-white">
                </div>

                <!--
                    Asked only when there is waste to explain, so an ordinary booking is still
                    two numbers and SAVE. Native picker for the same reason as the machine and
                    downtime lists: gloves, no keyboard.
                -->
                <div v-if="Number(wasteQty) > 0">
                    <label class="mb-1 block text-xl">নষ্টের কারণ · What was the waste?</label>
                    <select v-model="wasteType" class="w-full rounded-xl bg-white/10 px-5 py-5 text-2xl text-white">
                        <option value="" class="text-slate-900">— কারণ · reason —</option>
                        <option v-for="type in WASTE_TYPES" :key="type.value" :value="type.value" class="text-slate-900">
                            {{ type.label }}
                        </option>
                    </select>
                </div>
                <p class="text-lg text-slate-400">
                    এই ধাপে আর সর্বোচ্চ {{ Number(operation.remaining_allowance).toLocaleString() }} {{ unit }} বুক করা যাবে
                    · At most {{ Number(operation.remaining_allowance).toLocaleString() }} {{ operation.unit }} more can be booked on this step
                </p>

                <!-- Input beyond the plan is allowed, but it has to be explained (J3). -->
                <div v-if="Number(inputQty) > Number(operation.planned_qty) * 1.03">
                    <label class="mb-1 block text-xl">
                        কারণ · Why more than {{ Number(operation.planned_qty).toLocaleString() }} {{ operation.unit }}?
                    </label>
                    <input v-model="overrideReason" class="w-full rounded-xl bg-white/10 px-5 py-5 text-2xl text-white">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <button class="floor-btn bg-slate-600" @click="mode = null">বাতিল · CANCEL</button>
                    <button
                        class="floor-btn bg-emerald-500 disabled:opacity-30"
                        :disabled="busy !== null || (Number(wasteQty) > 0 && !wasteType)"
                        @click="log"
                    >
                        {{ busy === 'log' ? '…' : 'সেভ · SAVE' }}
                    </button>
                </div>
            </div>

            <div v-else-if="mode === 'no-output'" class="space-y-4">
                <p class="rounded-xl bg-amber-500 px-5 py-4 text-xl font-semibold text-slate-900">
                    কিছু রেকর্ড হয়নি · Nothing was booked against this operation.
                </p>

                <div>
                    <label class="mb-1 block text-xl">কারণ · Reason</label>
                    <input v-model="noOutputReason" class="w-full rounded-xl bg-white/10 px-5 py-5 text-2xl text-white">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <button class="floor-btn bg-slate-600" @click="mode = null">বাতিল · CANCEL</button>
                    <button class="floor-btn bg-emerald-500 disabled:opacity-30" :disabled="!noOutputReason || busy !== null" @click="finish">
                        {{ busy === 'finish' ? '…' : 'শেষ · FINISH' }}
                    </button>
                </div>
            </div>

            <div v-else-if="mode === 'downtime'" class="space-y-4">
                <!--
                    Native picker on purpose: the floor terminal is touched with gloves and has
                    no keyboard, so the OS wheel beats a filter box the operator cannot type in.
                -->
                <select v-model="downtimeReasonId" class="w-full rounded-xl bg-white/10 px-5 py-5 text-2xl text-white">
                    <option value="" class="text-slate-900">— কারণ · reason —</option>
                    <option v-for="reason in downtimeReasons" :key="reason.id" :value="reason.id" class="text-slate-900">
                        {{ reason.name }}
                    </option>
                </select>
                <input
                    v-model="downtimeMinutes"
                    inputmode="numeric"
                    placeholder="মিনিট · minutes"
                    class="w-full rounded-xl bg-white/10 px-5 py-5 text-4xl tnum text-white"
                >
                <select v-if="shifts.length" v-model="shiftId" class="w-full rounded-xl bg-white/10 px-5 py-4 text-2xl text-white">
                    <option value="" class="text-slate-900">— শিফট · shift —</option>
                    <option v-for="shift in shifts" :key="shift.id" :value="shift.id" class="text-slate-900">
                        {{ shift.name }}
                    </option>
                </select>
                <div class="grid grid-cols-2 gap-3">
                    <button class="floor-btn bg-slate-600" @click="mode = null">বাতিল · CANCEL</button>
                    <button
                        class="floor-btn bg-amber-500 text-slate-900 disabled:opacity-30"
                        :disabled="!downtimeReasonId || !downtimeMinutes || busy !== null"
                        @click="logDowntime"
                    >
                        {{ busy === 'downtime' ? '…' : 'সেভ · SAVE' }}
                    </button>
                </div>
            </div>
        </div>
    </FloorLayout>
</template>
