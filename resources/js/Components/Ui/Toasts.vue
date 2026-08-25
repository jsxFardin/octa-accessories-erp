<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

/**
 * Toasts are keyed by an arriving sequence id, not by message text. Keying the dismissed set
 * by text meant the second "Order released." of a session was suppressed forever — the flash
 * for a repeated action never showed again after the first one was dismissed or timed out.
 */
let sequence = 0;
const toasts = ref([]);

const flash = computed(() => page.props.flash ?? {});

function remove(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

/**
 * Errors stay until dismissed. A blocked release or a negative-stock rejection names the rule
 * that stopped it, and that message is the whole point — it must not slide away in four
 * seconds while the supervisor is reading it.
 */
watch(
    flash,
    (value) => {
        ['success', 'warning', 'error'].forEach((tone) => {
            if (!value?.[tone]) return;

            const id = ++sequence;
            toasts.value.push({ id, tone, message: value[tone] });

            if (tone !== 'error') {
                setTimeout(() => remove(id), 5000);
            }
        });
    },
    { immediate: true },
);

const TONES = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    warning: 'border-amber-200 bg-amber-50 text-amber-900',
    error: 'border-rose-200 bg-rose-50 text-rose-900',
};
</script>

<template>
    <!-- aria-live: flash messages are the only confirmation most writes get; without a live
         region a screen-reader user saves and hears nothing. Errors interrupt (assertive). -->
    <div
        class="pointer-events-none fixed top-4 right-4 z-[100] flex w-full max-w-sm flex-col gap-2"
        aria-live="polite"
    >
        <TransitionGroup
            enter-active-class="transition duration-200"
            enter-from-class="translate-x-4 opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="translate-x-4 opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex items-start gap-3 rounded-lg border px-3 py-2.5 text-sm shadow-lg"
                :class="TONES[toast.tone]"
                :role="toast.tone === 'error' ? 'alert' : 'status'"
            >
                <p class="min-w-0 flex-1 break-words whitespace-pre-line">{{ toast.message }}</p>
                <button
                    class="shrink-0 text-lg leading-none opacity-50 transition hover:opacity-100"
                    aria-label="Dismiss"
                    @click="remove(toast.id)"
                >
                    &times;
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
