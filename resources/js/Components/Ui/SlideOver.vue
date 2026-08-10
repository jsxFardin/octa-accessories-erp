<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { closeOverlay, isTopOverlay, openOverlay } from '@/composables/useOverlay';

/**
 * A right-hand panel for create and edit.
 *
 * Master-data forms are short and are opened from a list you were reading — a full page load
 * loses your place in that list, your filters and your scroll position. A modal in the middle
 * of the screen covers the very rows you were comparing against; a panel at the edge does not.
 * Documents keep their full pages: a quotation with twelve costed lines is not a side panel.
 */
const open = defineModel('open', { type: Boolean, default: false });

const props = defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    width: { type: String, default: 'max-w-xl' },
});

/** This instance's place in the overlay stack, while it is open. */
const token = ref(null);

function close() {
    open.value = false;
}

function onKeydown(event) {
    // Only when nothing is stacked on top: a modal opened from this panel answers Escape first.
    if (event.key === 'Escape' && open.value && isTopOverlay(token.value)) close();
}

watch(open, (value) => {
    if (value) {
        token.value ??= openOverlay();

        return;
    }

    closeOverlay(token.value);
    token.value = null;
});

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
    closeOverlay(token.value);
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[70]">
            <Transition
                enter-active-class="transition duration-150"
                enter-from-class="opacity-0"
                leave-active-class="transition duration-100"
                leave-to-class="opacity-0"
                appear
            >
                <div class="fixed inset-0 bg-slate-900/40" @click="close" />
            </Transition>

            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-x-full"
                leave-active-class="transition duration-150 ease-in"
                leave-to-class="translate-x-full"
                appear
            >
                <div
                    class="fixed inset-y-0 right-0 flex w-full flex-col bg-white shadow-2xl"
                    :class="width"
                    role="dialog"
                    aria-modal="true"
                >
                    <header class="flex items-start gap-3 border-b border-slate-200 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-semibold text-ink-900">{{ title }}</h2>
                            <p v-if="subtitle" class="mt-0.5 text-xs leading-relaxed text-ink-500">{{ subtitle }}</p>
                        </div>

                        <button
                            class="rounded p-1 text-ink-400 transition hover:bg-slate-100 hover:text-ink-700"
                            aria-label="Close"
                            @click="close"
                        >
                            <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M6 6l8 8M14 6l-8 8" stroke-linecap="round" />
                            </svg>
                        </button>
                    </header>

                    <!-- The panel scrolls, not the page behind it. -->
                    <div class="flex-1 overflow-y-auto px-4 py-4">
                        <slot />
                    </div>

                    <footer
                        v-if="$slots.footer"
                        class="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3"
                    >
                        <slot name="footer" :close="close" />
                    </footer>
                </div>
            </Transition>
        </div>
    </Teleport>
</template>
