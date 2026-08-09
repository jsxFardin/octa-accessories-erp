<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import FloorLayout from '@/Layouts/FloorLayout.vue';


const props = defineProps({ machines: { type: Array, default: () => [] } });

const cardNo = ref('');
const pin = ref('');
const machineCode = ref('');
const error = ref(null);
const busy = ref(false);

/**
 * Badge scan plus a short PIN, in exchange for a shift-length token (06-rbac §6). The token
 * lives in localStorage so the terminal survives a page reload — and a wifi outage — without
 * sending the operator back to the office for a password.
 */
async function signIn() {
    busy.value = true;
    error.value = null;

    try {
        const response = await fetch('/api/v1/device/session', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ card_no: cardNo.value, pin: pin.value, machine_code: machineCode.value || null }),
        });

        if (!response.ok) {
            error.value = 'ব্যাজ বা পিন মেলেনি · Badge or PIN not recognised';

            return;
        }

        const session = await response.json();
        localStorage.setItem('octa.device_session', JSON.stringify(session));
        router.visit(`/floor/queue?machine=${machineCode.value}`);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <FloorLayout>
        <Head title="Floor terminal" />

        <template #title>শপ ফ্লোর টার্মিনাল</template>
        <template #subtitle>Scan your badge, then enter your PIN</template>

        <div class="mx-auto max-w-md space-y-5">
            <div>
                <label class="mb-2 block text-lg font-semibold">ব্যাজ · Badge</label>
                <input
                    v-model="cardNo"
                    autofocus
                    class="w-full rounded-xl bg-white/10 px-5 py-5 text-3xl tracking-widest text-white outline-none focus:bg-white/20"
                    placeholder="BADGE-0000"
                >
            </div>

            <div>
                <label class="mb-2 block text-lg font-semibold">পিন · PIN</label>
                <input
                    v-model="pin"
                    type="password"
                    inputmode="numeric"
                    class="w-full rounded-xl bg-white/10 px-5 py-5 text-3xl tracking-[0.5em] text-white outline-none focus:bg-white/20"
                    placeholder="0000"
                >
            </div>

            <div>
                <label class="mb-2 block text-lg font-semibold">মেশিন · Machine</label>
                <select v-model="machineCode" class="w-full rounded-xl bg-white/10 px-5 py-4 text-2xl text-white outline-none">
                    <option value="" class="text-slate-900">— any —</option>
                    <option v-for="machine in machines" :key="machine.id" :value="machine.code" class="text-slate-900">
                        {{ machine.code }} — {{ machine.name }}
                    </option>
                </select>
            </div>

            <p v-if="error" class="rounded-xl bg-rose-600 px-5 py-4 text-xl font-semibold">{{ error }}</p>

            <button
                class="floor-btn w-full bg-emerald-500 text-white disabled:opacity-40"
                :disabled="!cardNo || !pin || busy"
                @click="signIn"
            >
                {{ busy ? '…' : 'শুরু · SIGN IN' }}
            </button>
        </div>
    </FloorLayout>
</template>
