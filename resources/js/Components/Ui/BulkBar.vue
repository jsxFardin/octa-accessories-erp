<script setup>
import Button from '@/Components/Ui/Button.vue';
import Icon from '@/Components/Ui/Icon.vue';

/**
 * The bar that appears once rows are selected.
 *
 * Docked to the bottom rather than replacing the page header, so the list stays readable while
 * you are choosing what to act on — the point of a bulk action is that you are still looking at
 * the rows.
 */
defineProps({
    count: { type: Number, required: true },
    /** `[{ label, tone, onSelect }]` */
    actions: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['clear']);
</script>

<template>
    <Transition
        enter-active-class="transition duration-150"
        enter-from-class="translate-y-full opacity-0"
        leave-active-class="transition duration-100"
        leave-to-class="translate-y-full opacity-0"
    >
        <div v-if="count > 0" class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 backdrop-blur print:hidden">
            <div class="mx-auto flex max-w-[1600px] flex-wrap items-center gap-3 px-4 py-2.5">
                <span class="flex items-center gap-2 text-sm text-ink-800">
                    <span class="flex size-6 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold tnum text-white">
                        {{ count }}
                    </span>
                    selected
                </span>

                <button class="text-xs text-ink-500 transition hover:text-ink-800 hover:underline" @click="emit('clear')">
                    Clear
                </button>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <Button
                        v-for="action in actions"
                        :key="action.label"
                        size="sm"
                        :variant="action.tone ?? 'secondary'"
                        :loading="busy"
                        @click="action.onSelect"
                    >
                        <Icon v-if="action.icon" :name="action.icon" size="size-3.5" />
                        {{ action.label }}
                    </Button>
                </div>
            </div>
        </div>
    </Transition>
</template>
