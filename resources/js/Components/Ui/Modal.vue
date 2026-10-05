<script setup>
import { onMounted, onUnmounted, ref, useId, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useDirtyClose } from '@/composables/useDirtyClose';
import { useFocusTrap } from '@/composables/useFocusTrap';
import { closeOverlay, isTopOverlay, openOverlay } from '@/composables/useOverlay';

const open = defineModel('open', { type: Boolean, default: false });

const props = defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    width: { type: String, default: 'max-w-lg' },
    closeOnBackdrop: { type: Boolean, default: true },
    /** The form inside has unsaved input — see `useDirtyClose`. */
    dirty: { type: Boolean, default: false },
});

/**
 * What the server refused while this was open, shown inside it.
 *
 * A refusal used to surface only as a toast in the corner, after the dialog had already closed.
 * The dialog now stays open (`plugins/refusals.js`), so the reason belongs here, beside the
 * button that was pressed and the input that needs changing.
 */
const page = usePage();
const refusal = ref(null);

watch(() => page.props.flash, (flash) => {
    if (open.value && flash?.error) refusal.value = flash.error;
});

/** This instance's place in the overlay stack, while it is open. */
const token = ref(null);

function close() {
    open.value = false;
}

const { markTouched, reset, requestClose, onBackdrop } = useDirtyClose(() => open.value, () => props.dirty, close);

function onKeydown(event) {
    // Only the innermost overlay answers Escape, or closing a modal opened from a slide-over
    // would close the panel behind it in the same keypress.
    if (event.key === 'Escape' && open.value && isTopOverlay(token.value)) {
        requestClose();
    }
}

watch(open, (value) => {
    reset();
    refusal.value = null;

    if (value) {
        token.value ??= openOverlay();

        return;
    }

    closeOverlay(token.value);
    token.value = null;
});

/** The dialog element, and the ids that give it a name a screen reader announces. */
const panel = ref(null);
const titleId = useId();
const subtitleId = useId();

useFocusTrap(panel, () => open.value, () => isTopOverlay(token.value));

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
    closeOverlay(token.value);
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-100"
            leave-to-class="opacity-0"
        >
            <!--
                Overlay stack: slide-overs and the palette 70, modals 75, confirmations 80,
                popovers 90, toasts 100. A modal opened from inside a slide-over — the import
                guidelines — has to sit above the panel that opened it, and a confirmation above
                both.

                Popovers sit above every panel on purpose. They used to be at 60, below all of
                them, so a select inside a slide-over opened its list *behind* the panel and the
                click that should have picked an option landed on the backdrop and shut the
                panel instead. A popover is always a child of the thing on top, whatever that is.
            -->
            <div v-if="open" class="fixed inset-0 z-[75] overflow-y-auto">
                <div
                    class="fixed inset-0 bg-slate-900/50 backdrop-blur-[1px]"
                    @click="closeOnBackdrop && onBackdrop()"
                />

                <div class="relative flex min-h-full items-center justify-center p-4">
                    <div
                        ref="panel"
                        class="w-full rounded-lg bg-white shadow-xl ring-1 ring-slate-900/5 focus:outline-none"
                        :class="width"
                        role="dialog"
                        aria-modal="true"
                        :aria-labelledby="title ? titleId : null"
                        :aria-describedby="title && subtitle ? subtitleId : null"
                        tabindex="-1"
                        @input="markTouched"
                        @change="markTouched"
                    >
                        <header v-if="title" class="flex items-start gap-3 border-b border-slate-200 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <h2 :id="titleId" class="text-sm font-semibold text-ink-900">{{ title }}</h2>
                                <p v-if="subtitle" :id="subtitleId" class="mt-0.5 text-xs text-ink-600">{{ subtitle }}</p>
                            </div>

                            <!-- A way out that is not Escape or a click outside: there was none. -->
                            <button
                                class="-m-1 flex size-8 shrink-0 items-center justify-center rounded text-ink-600 transition hover:bg-slate-100 hover:text-ink-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                type="button"
                                aria-label="Close"
                                @click="requestClose"
                            >
                                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path d="M6 6l8 8M14 6l-8 8" stroke-linecap="round" />
                                </svg>
                            </button>
                        </header>

                        <div class="px-4 py-4">
                            <p
                                v-if="refusal"
                                role="alert"
                                class="mb-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs leading-relaxed whitespace-pre-line text-rose-800"
                            >
                                {{ refusal }}
                            </p>
                            <slot />
                        </div>

                        <footer
                            v-if="$slots.footer"
                            class="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3"
                        >
                            <slot name="footer" :close="close" />
                        </footer>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
