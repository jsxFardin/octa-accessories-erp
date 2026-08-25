<script setup>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import Button from '@/Components/Ui/Button.vue';
import Icon from '@/Components/Ui/Icon.vue';
import { useConfirm } from '@/composables/useConfirm';
import { closeOverlay, isTopOverlay, openOverlay } from '@/composables/useOverlay';

/** Mounted once in the layout; every `confirm()` call anywhere renders through this. */
const { state, answer } = useConfirm();

const cancelButton = ref(null);
const confirmButton = ref(null);
const panel = ref(null);

/**
 * Part of the overlay stack like every other overlay: without the token, Escape on a confirm
 * also closed the modal underneath it (that modal still believed it was top), and the page
 * kept scrolling behind the dialog.
 */
let token = null;
let previouslyFocused = null;

watch(
    () => state.open,
    async (open) => {
        if (open) {
            token = openOverlay();
            previouslyFocused = document.activeElement;
            await nextTick();
            // Focus lands on the *safe* button for destructive confirms — Enter then cancels
            // rather than deleting. Non-destructive confirms focus the primary action.
            (state.tone === 'danger' ? cancelButton.value : confirmButton.value)?.$el?.focus();
        } else if (token !== null) {
            closeOverlay(token);
            token = null;
            previouslyFocused?.focus?.();
            previouslyFocused = null;
        }
    },
);

function onKeydown(event) {
    if (!state.open || (token !== null && !isTopOverlay(token))) return;

    if (event.key === 'Escape') {
        event.stopPropagation();
        answer(false);
    }

    // Keep Tab inside the dialog: two buttons, so Tab simply toggles between them.
    if (event.key === 'Tab') {
        event.preventDefault();
        const target = document.activeElement === cancelButton.value?.$el
            ? confirmButton.value?.$el
            : cancelButton.value?.$el;
        target?.focus();
    }

    // No global Enter-to-confirm: Enter activates whichever button holds focus, which for a
    // danger dialog is Cancel. A user finishing a form with Enter cannot confirm a delete
    // they haven't read.
}

onMounted(() => document.addEventListener('keydown', onKeydown, true));
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown, true);
    if (token !== null) closeOverlay(token);
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-100"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-75"
            leave-to-class="opacity-0"
        >
            <div v-if="state.open" class="fixed inset-0 z-[80]">
                <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-[1px]" @click="answer(false)" />

                <div class="relative flex min-h-full items-center justify-center p-4">
                    <div ref="panel" class="w-full max-w-md rounded-lg bg-white p-4 shadow-xl" role="alertdialog" aria-modal="true" :aria-label="state.title">
                        <div class="flex gap-3">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-full"
                                :class="state.tone === 'danger' ? 'bg-rose-50 text-rose-600' : 'bg-brand-50 text-brand-600'"
                            >
                                <Icon :name="state.tone === 'danger' ? 'remove' : 'check'" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <h2 class="text-sm font-semibold text-ink-900">{{ state.title }}</h2>
                                <p v-if="state.message" class="mt-1 text-sm leading-relaxed text-ink-600">
                                    {{ state.message }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 flex justify-end gap-2">
                            <Button ref="cancelButton" size="sm" @click="answer(false)">{{ state.cancelLabel }}</Button>
                            <Button
                                ref="confirmButton"
                                size="sm"
                                :variant="state.tone === 'danger' ? 'danger' : 'primary'"
                                @click="answer(true)"
                            >
                                {{ state.confirmLabel }}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
