<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const dismissed = ref(new Set());

const flash = computed(() => page.props.flash ?? {});

const toasts = computed(() =>
    ['success', 'warning', 'error']
        .filter((tone) => flash.value[tone] && !dismissed.value.has(flash.value[tone]))
        .map((tone) => ({ tone, message: flash.value[tone] })),
);

const TONES = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    warning: 'border-amber-200 bg-amber-50 text-amber-900',
    error: 'border-rose-200 bg-rose-50 text-rose-900',
};

/**
 * Errors stay until dismissed. A blocked release or a negative-stock rejection names the rule
 * that stopped it, and that message is the whole point — it must not slide away in four
 * seconds while the supervisor is reading it.
 */
watch(toasts, (value) => {
    value
        .filter((toast) => toast.tone !== 'error')
        .forEach((toast) => {
            setTimeout(() => dismissed.value.add(toast.message), 5000);
        });
});
</script>

<template>
    <div class="pointer-events-none fixed top-4 right-4 z-[100] flex w-full max-w-sm flex-col gap-2">
        <TransitionGroup
            enter-active-class="transition duration-200"
            enter-from-class="translate-x-4 opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="translate-x-4 opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.message"
                class="pointer-events-auto flex items-start gap-3 rounded-lg border px-3 py-2.5 text-sm shadow-lg"
                :class="TONES[toast.tone]"
            >
                <p class="min-w-0 flex-1 break-words whitespace-pre-line">{{ toast.message }}</p>
                <button
                    class="shrink-0 text-lg leading-none opacity-50 transition hover:opacity-100"
                    aria-label="Dismiss"
                    @click="dismissed.add(toast.message)"
                >
                    &times;
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
