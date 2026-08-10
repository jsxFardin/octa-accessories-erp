<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { closeOverlay, isTopOverlay, openOverlay } from '@/composables/useOverlay';

const open = defineModel('open', { type: Boolean, default: false });

const props = defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    width: { type: String, default: 'max-w-lg' },
    closeOnBackdrop: { type: Boolean, default: true },
});

/** This instance's place in the overlay stack, while it is open. */
const token = ref(null);

function close() {
    open.value = false;
}

function onKeydown(event) {
    // Only the innermost overlay answers Escape, or closing a modal opened from a slide-over
    // would close the panel behind it in the same keypress.
    if (event.key === 'Escape' && open.value && isTopOverlay(token.value)) {
        close();
    }
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
        <Transition
            enter-active-class="transition duration-150"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-100"
            leave-to-class="opacity-0"
        >
            <!--
                Overlay stack: dropdowns and toasts 60, slide-overs and the palette 70, modals
                75, confirmations 80. A modal opened from inside a slide-over — the import
                guidelines — has to sit above the panel that opened it, and a confirmation has
                to sit above both.
            -->
            <div v-if="open" class="fixed inset-0 z-[75] overflow-y-auto">
                <div
                    class="fixed inset-0 bg-slate-900/50 backdrop-blur-[1px]"
                    @click="closeOnBackdrop && close()"
                />

                <div class="relative flex min-h-full items-center justify-center p-4">
                    <div
                        class="w-full rounded-lg bg-white shadow-xl ring-1 ring-slate-900/5"
                        :class="width"
                        role="dialog"
                        aria-modal="true"
                    >
                        <header v-if="title" class="border-b border-slate-200 px-4 py-3">
                            <h3 class="text-sm font-semibold text-ink-900">{{ title }}</h3>
                            <p v-if="subtitle" class="mt-0.5 text-xs text-ink-500">{{ subtitle }}</p>
                        </header>

                        <div class="px-4 py-4">
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
