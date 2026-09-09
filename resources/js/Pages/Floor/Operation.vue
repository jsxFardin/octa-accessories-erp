<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import FloorLayout from '@/Layouts/FloorLayout.vue';
import { useOfflineQueue } from '@/Composables/useOfflineQueue';


const props = defineProps({
    operation: { type: Object, required: true },
    downtimeReasons: { type: Array, default: () => [] },
    shifts: { type: Array, default: () => [] },
});

const { send, pending, rejected, online } = useOfflineQueue();

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

async function start() {
    if (!handled(await send(`/api/v1/operations/${props.operation.id}/start`, {}))) return;

    message.value = 'শুরু হয়েছে · Started';
    router.reload();
}

async function log() {
    const result = await send(`/api/v1/operations/${props.operation.id}/log`, {
        good_qty: Number(goodQty.value || 0),
        waste_qty: Number(wasteQty.value || 0),
        input_qty: Number(inputQty.value || 0),
        input_override_reason: overrideReason.value || null,
        waste_type: Number(wasteQty.value || 0) > 0 ? wasteType.value || null : null,
    });

    if (!handled(result)) return;

    message.value = 'রেকর্ড হয়েছে · Logged';
    mode.value = null;
    goodQty.value = wasteQty.value = inputQty.value = overrideReason.value = wasteType.value = '';
    router.reload();
}

async function finish() {
    const result = await send(`/api/v1/operations/${props.operation.id}/finish`, {
        no_output_reason: noOutputReason.value || null,
    });

    // Nothing booked: ask why rather than closing a shift's worth of machine time at zero.
    if (result?.error && result.status === 422 && !noOutputReason.value) {
        mode.value = 'no-output';
        error.value = null;

        return;
    }

    if (!handled(result)) return;

    router.visit('/floor/queue');
}

async function logDowntime() {
    const result = await send(`/api/v1/operations/${props.operation.id}/downtime`, {
        downtime_reason_id: Number(downtimeReasonId.value),
        minutes: Number(downtimeMinutes.value || 0),
        // The shifts were fetched for this screen and then never sent, so every stop landed
        // with no shift against it and no report could break downtime down by one.
        shift_id: shiftId.value ? Number(shiftId.value) : null,
    });

    if (!handled(result)) return;

    message.value = 'ডাউনটাইম রেকর্ড · Downtime logged';
    mode.value = null;
    downtimeMinutes.value = '';
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
            <div class="flex items-center gap-3">
                <span class="rounded-full px-4 py-2 text-lg font-bold" :class="online ? 'bg-emerald-600' : 'bg-amber-500 text-slate-900'">
                    {{ online ? 'ONLINE' : `OFFLINE · ${pending}` }}
                </span>

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
            <p v-if="message" class="rounded-xl bg-emerald-600 px-5 py-4 text-xl font-semibold">{{ message }}</p>

            <!-- Refusals are loud on purpose: the operator is standing at a machine. -->
            <p v-if="error" class="rounded-xl bg-rose-600 px-5 py-4 text-xl font-semibold">{{ error }}</p>

            <p v-if="rejected" class="rounded-xl bg-amber-500 px-5 py-4 text-lg font-semibold text-slate-900">
                {{ rejected }} রেকর্ড সার্ভার নেয়নি · {{ rejected }} write(s) refused by the server — call your supervisor
            </p>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-2xl bg-white/5 p-4">
                    <p class="text-sm text-slate-400">পরিকল্পিত · Planned</p>
                    <p class="text-3xl font-bold tnum">{{ Number(operation.planned_qty).toLocaleString() }}</p>
                </div>
                <div class="rounded-2xl bg-white/5 p-4">
                    <p class="text-sm text-slate-400">ইনপুট · Input</p>
                    <p class="text-3xl font-bold tnum">{{ Number(operation.input_qty).toLocaleString() }}</p>
                </div>
                <div class="rounded-2xl bg-white/5 p-4">
                    <p class="text-sm text-slate-400">ভালো · Good</p>
                    <p class="text-3xl font-bold tnum text-emerald-400">{{ Number(operation.good_qty).toLocaleString() }}</p>
                </div>
                <div class="rounded-2xl bg-white/5 p-4">
                    <p class="text-sm text-slate-400">নষ্ট · Waste</p>
                    <p class="text-3xl font-bold tnum text-rose-400">{{ Number(operation.waste_qty).toLocaleString() }}</p>
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
                    :disabled="operation.status === 'in_progress'"
                    @click="start"
                >
                    শুরু · START
                </button>
                <button class="floor-btn bg-sky-500" @click="mode = 'log'">আউটপুট · OUTPUT</button>
                <button class="floor-btn bg-amber-500 text-slate-900" @click="mode = 'downtime'">ডাউনটাইম · DOWNTIME</button>
                <button class="floor-btn bg-slate-600" @click="finish">শেষ · FINISH</button>
            </div>

            <div v-else-if="mode === 'log'" class="space-y-4">
                <div>
                    <label class="mb-1 block text-xl">ইনপুট · Input received</label>
                    <input v-model="inputQty" inputmode="decimal" class="w-full rounded-xl bg-white/10 px-5 py-5 text-4xl tnum text-white">
                </div>
                <div>
                    <label class="mb-1 block text-xl">ভালো · Good</label>
                    <input v-model="goodQty" inputmode="decimal" class="w-full rounded-xl bg-white/10 px-5 py-5 text-4xl tnum text-white">
                </div>
                <div>
                    <label class="mb-1 block text-xl">নষ্ট · Waste</label>
                    <input v-model="wasteQty" inputmode="decimal" class="w-full rounded-xl bg-white/10 px-5 py-5 text-4xl tnum text-white">
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
                    J3: এই ধাপে সর্বোচ্চ {{ Number(operation.remaining_allowance).toLocaleString() }} বুক করা যাবে
                </p>

                <!-- Input beyond the plan is allowed, but it has to be explained (J3). -->
                <div v-if="Number(inputQty) > Number(operation.planned_qty) * 1.03">
                    <label class="mb-1 block text-xl">
                        কারণ · Why more than {{ Number(operation.planned_qty).toLocaleString() }}?
                    </label>
                    <input v-model="overrideReason" class="w-full rounded-xl bg-white/10 px-5 py-5 text-2xl text-white">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <button class="floor-btn bg-slate-600" @click="mode = null">বাতিল · CANCEL</button>
                    <button
                        class="floor-btn bg-emerald-500 disabled:opacity-30"
                        :disabled="Number(wasteQty) > 0 && !wasteType"
                        @click="log"
                    >
                        সেভ · SAVE
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
                    <button class="floor-btn bg-emerald-500 disabled:opacity-30" :disabled="!noOutputReason" @click="finish">
                        শেষ · FINISH
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
                        :disabled="!downtimeReasonId || !downtimeMinutes"
                        @click="logDowntime"
                    >
                        সেভ · SAVE
                    </button>
                </div>
            </div>
        </div>
    </FloorLayout>
</template>
