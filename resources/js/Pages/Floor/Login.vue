<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import FloorLayout from '@/Layouts/FloorLayout.vue';


defineProps({
    machines: { type: Array, default: () => [] },
    // The name of whoever is already signed in with a password and may run the terminal.
    signedInAs: { type: String, default: null },
});

/**
 * Badge scan plus a short PIN, in exchange for a shift-length session (06-rbac §6).
 *
 * This used to `fetch` an API token, drop it in localStorage and then visit `/floor/queue` —
 * a `web` route behind `auth`. A kiosk browser with no prior session was bounced silently to
 * the password login, so the badge screen only worked for someone who had already signed in
 * another way. The post now signs the operator in properly and the queue is handed the token
 * for the offline replay.
 */
const form = useForm({ card_no: '', pin: '', machine_code: '' });
const carryOn = useForm({ machine_code: '' });

const showDeskDoor = ref(false);

function signIn() {
    form.post('/floor/session', { preserveScroll: true, onFinish: () => form.reset('pin') });
}

function continueAsDeskUser() {
    carryOn.machine_code = form.machine_code;
    carryOn.post('/floor/session/continue', { preserveScroll: true });
}
</script>

<template>
    <FloorLayout>
        <Head title="Shop floor terminal" />

        <template #title>শপ ফ্লোর টার্মিনাল · Shop floor terminal</template>
        <template #subtitle>Scan your badge, then enter your PIN</template>

        <div class="mx-auto max-w-md space-y-5">
            <!--
                What this screen is. Desk users reach it from the sidebar out of curiosity, meet
                a badge prompt for credentials they were never issued, and conclude the feature
                is broken. One line is cheaper than that support call.
            -->
            <p class="rounded-xl bg-white/5 px-5 py-4 text-lg leading-relaxed text-slate-300">
                This is the <span class="font-bold text-white">operator screen</span> — the kiosk beside the machine,
                where output, downtime and waste are booked against a running job card.
                <br>
                Planning a job card or reading its progress instead? Close this tab and use
                <span class="font-bold text-white">Production → Job cards</span> at your desk.
            </p>

            <div>
                <label class="mb-2 block text-lg font-semibold">ব্যাজ · Badge</label>
                <input
                    v-model="form.card_no"
                    autofocus
                    class="w-full rounded-xl bg-white/10 px-5 py-5 text-3xl tracking-widest text-white outline-none focus:bg-white/20"
                    placeholder="BADGE-0000"
                    @keyup.enter="signIn"
                >
            </div>

            <div>
                <label class="mb-2 block text-lg font-semibold">পিন · PIN</label>
                <input
                    v-model="form.pin"
                    type="password"
                    inputmode="numeric"
                    class="w-full rounded-xl bg-white/10 px-5 py-5 text-3xl tracking-[0.5em] text-white outline-none focus:bg-white/20"
                    placeholder="0000"
                    @keyup.enter="signIn"
                >
                <p class="mt-2 text-base text-slate-400">
                    Badge number and PIN are issued by an administrator under Configuration → Users.
                </p>
            </div>

            <div>
                <label class="mb-2 block text-lg font-semibold">মেশিন · Machine</label>
                <!--
                    Native picker on purpose: the floor terminal is touched with gloves and has
                    no keyboard, so the OS wheel beats a filter box the operator cannot type in.
                -->
                <select v-model="form.machine_code" class="w-full rounded-xl bg-white/10 px-5 py-4 text-2xl text-white outline-none">
                    <option value="" class="text-slate-900">— any —</option>
                    <option v-for="machine in machines" :key="machine.id" :value="machine.code" class="text-slate-900">
                        {{ machine.code }} — {{ machine.name }}
                    </option>
                </select>
                <p class="mt-2 text-base text-slate-400">
                    Filters your work queue to this machine and the work its group has not been pinned yet.
                    Leave on <span class="font-semibold">any</span> to see every machine in your unit.
                </p>
            </div>

            <p v-if="form.errors.card_no || form.errors.pin" class="rounded-xl bg-rose-600 px-5 py-4 text-xl font-semibold">
                {{ form.errors.card_no ?? form.errors.pin }}
            </p>

            <button
                class="floor-btn w-full bg-emerald-500 text-white disabled:opacity-40"
                :disabled="!form.card_no || !form.pin || form.processing"
                @click="signIn"
            >
                {{ form.processing ? '…' : 'শুরু · SIGN IN' }}
            </button>

            <!--
                The second door. A supervisor who signed in with a password has already proved
                who they are; a badge and PIN on top is a second login for no extra assurance.
                Folded away by default so it never competes with the badge fields the operator
                is standing here to use.
            -->
            <div v-if="signedInAs" class="border-t border-white/10 pt-5">
                <button
                    v-if="!showDeskDoor"
                    class="w-full rounded-xl px-5 py-3 text-lg text-slate-400 underline underline-offset-4 hover:text-white"
                    @click="showDeskDoor = true"
                >
                    No badge? You are already signed in as {{ signedInAs }}
                </button>

                <div v-else class="space-y-3">
                    <p class="text-lg text-slate-300">
                        Continue as <span class="font-bold text-white">{{ signedInAs }}</span>.
                        Anything booked will be recorded under that name.
                    </p>
                    <button
                        class="floor-btn w-full bg-sky-500 text-white disabled:opacity-40"
                        :disabled="carryOn.processing"
                        @click="continueAsDeskUser"
                    >
                        {{ carryOn.processing ? '…' : 'CONTINUE WITHOUT A BADGE' }}
                    </button>
                </div>
            </div>
        </div>
    </FloorLayout>
</template>
