<script setup>
import { onMounted, onUnmounted } from 'vue';
import Button from '@/Components/Ui/Button.vue';
import Icon from '@/Components/Ui/Icon.vue';
import { useConfirm } from '@/composables/useConfirm';

/** Mounted once in the layout; every `confirm()` call anywhere renders through this. */
const { state, answer } = useConfirm();

function onKeydown(event) {
    if (!state.open) return;

    if (event.key === 'Escape') answer(false);
    if (event.key === 'Enter') answer(true);
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));
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
                    <div class="w-full max-w-md rounded-lg bg-white p-4 shadow-xl" role="alertdialog" aria-modal="true">
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
                            <Button size="sm" @click="answer(false)">{{ state.cancelLabel }}</Button>
                            <Button
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
