<script setup>
import { onMounted, onUnmounted, watch } from 'vue';

const open = defineModel('open', { type: Boolean, default: false });

const props = defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    width: { type: String, default: 'max-w-lg' },
    closeOnBackdrop: { type: Boolean, default: true },
});

function close() {
    open.value = false;
}

function onKeydown(event) {
    if (event.key === 'Escape' && open.value) {
        close();
    }
}

watch(open, (value) => {
    document.body.style.overflow = value ? 'hidden' : '';
});

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
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
            <div v-if="open" class="fixed inset-0 z-50 overflow-y-auto">
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
