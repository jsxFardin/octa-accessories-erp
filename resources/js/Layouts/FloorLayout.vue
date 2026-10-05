<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { registerFloorServiceWorker, warmQueue } from '@/floor/serviceWorker';
import { guide, showEnglish, toggleEnglish } from '@/floor/dictionary';

/**
 * The shop-floor layout. Nothing here is shared with AppLayout on purpose: gloves, glare and
 * a four-button vocabulary are a different design language from a dense desk grid
 * (08-architecture §4).
 */
const page = usePage();
const flash = computed(() => page.props.flash ?? {});

/*
 * Registered here rather than in `app.js` because this layout is what every floor screen has
 * in common and nothing else uses it — the badge screen included, which is where a kiosk
 * being set up for the first time lands.
 *
 * The offline queue keeps four hours of writes across an outage. This keeps the application
 * that produces them: without it a reload with no link — a stray refresh, a tablet waking up,
 * Android reaping a backgrounded tab — left the operator on the browser's error page with a
 * shift of queued output stranded behind it.
 */
onMounted(() => {
    registerFloorServiceWorker();

    // Once the worker is running, make sure the work queue is saved in the shape a tap asks
    // for it in — see `warmQueue`. Deferred, because on the very first load the worker is
    // still installing and there is nothing listening yet.
    navigator.serviceWorker?.ready
        .then(() => warmQueue(page.version))
        .catch(() => {});
});

/*
 * A tap that cannot be served.
 *
 * Offline, the worker answers a reload from its cache but it cannot invent a screen nobody
 * has opened on this device — so opening an unvisited job card fails. Inertia's own handling
 * of a dead request is to do nothing visible, which at a machine is the worst of the options:
 * the operator taps the card again, and again, and then calls it broken.
 */
const unreachable = ref(false);

let stopListening = [];

onMounted(() => {
    stopListening = [
        // `networkError`, not `exception` — Inertia 3 renamed it, and a listener on the old
        // name is not an error, it simply never fires.
        router.on('networkError', () => {
            unreachable.value = true;
        }),
        // Any visit that does land clears it, including the retry that succeeds once the wifi
        // is back — the message must not outlive the outage that caused it.
        router.on('success', () => {
            unreachable.value = false;
        }),
    ];
});

onUnmounted(() => stopListening.forEach((stop) => stop()));
</script>

<template>
    <div class="floor-scope min-h-screen">
        <!-- Wraps: with a waiting count and Send now in it, the action strip is wider than a
             phone, and a header that cannot wrap pushed the whole terminal sideways. -->
        <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-b border-white/10 px-4 py-4 sm:px-6">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold"><slot name="title" /></h1>
                <p class="text-sm text-slate-300"><slot name="subtitle" /></p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <slot name="actions" />
                <!--
                    Long guidance is Bangla. This switches it — the whole terminal, on this
                    device — for the supervisor or engineer reading over a shoulder. It names
                    the language it switches *to*, in that language.
                -->
                <button
                    class="min-h-11 rounded-full border border-white/20 px-4 py-2 text-base font-semibold text-slate-200 hover:bg-white/10"
                    :aria-pressed="showEnglish"
                    data-language-toggle
                    @click="toggleEnglish"
                >
                    {{ showEnglish ? 'বাংলা' : 'English' }}
                </button>
            </div>
        </header>

        <div
            v-if="flash.error"
            class="mx-6 mt-4 rounded-xl bg-rose-600 px-5 py-4 text-lg font-semibold whitespace-pre-line"
        >
            {{ flash.error }}
        </div>
        <div v-if="flash.success" class="mx-6 mt-4 rounded-xl bg-emerald-600 px-5 py-4 text-lg font-semibold">
            {{ flash.success }}
        </div>

        <!--
            Named rather than silent. Booked work is safe either way — it is queued on this
            device and replays when the link returns — so the sentence says that too, or the
            operator stops working on the assumption that nothing is being recorded.
        -->
        <div
            v-if="unreachable"
            class="mx-6 mt-4 rounded-xl bg-amber-500 px-5 py-4 text-lg font-semibold text-slate-900"
        >
            {{ guide('screen_unreachable') }}
        </div>

        <main class="p-6">
            <slot />
        </main>
    </div>
</template>
