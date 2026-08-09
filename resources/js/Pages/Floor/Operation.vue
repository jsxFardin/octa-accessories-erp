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

const { send, pending, online } = useOfflineQueue();

const mode = ref(null);
const goodQty = ref('');
const wasteQty = ref('');
const inputQty = ref('');
const downtimeReasonId = ref('');
const downtimeMinutes = ref('');
const message = ref(null);

async function start() {
    await send(`/api/v1/operations/${props.operation.id}/start`, {});
    message.value = 'শুরু হয়েছে · Started';
    router.reload();
}

async function log() {
    await send(`/api/v1/operations/${props.operation.id}/log`, {
        good_qty: Number(goodQty.value || 0),
        waste_qty: Number(wasteQty.value || 0),
        input_qty: Number(inputQty.value || 0),
    });

    message.value = 'রেকর্ড হয়েছে · Logged';
    mode.value = null;
    goodQty.value = wasteQty.value = inputQty.value = '';
    router.reload();
}

async function finish() {
    await send(`/api/v1/operations/${props.operation.id}/finish`, {});
    router.visit('/floor/queue');
}

async function logDowntime() {
    await send(`/api/v1/operations/${props.operation.id}/downtime`, {
        downtime_reason_id: Number(downtimeReasonId.value),
        minutes: Number(downtimeMinutes.value || 0),
    });

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
            <span class="rounded-full px-4 py-2 text-lg font-bold" :class="online ? 'bg-emerald-600' : 'bg-amber-500 text-slate-900'">
                {{ online ? 'ONLINE' : `OFFLINE · ${pending}` }}
            </span>
        </template>

        <div class="space-y-5">
            <p v-if="message" class="rounded-xl bg-emerald-600 px-5 py-4 text-xl font-semibold">{{ message }}</p>

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
                <p class="text-lg text-slate-400">
                    J3: এই ধাপে সর্বোচ্চ {{ Number(operation.remaining_allowance).toLocaleString() }} বুক করা যাবে
                </p>
                <div class="grid grid-cols-2 gap-3">
                    <button class="floor-btn bg-slate-600" @click="mode = null">বাতিল · CANCEL</button>
                    <button class="floor-btn bg-emerald-500" @click="log">সেভ · SAVE</button>
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
