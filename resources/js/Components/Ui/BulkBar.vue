<script setup>
import Button from '@/Components/Ui/Button.vue';
import Icon from '@/Components/Ui/Icon.vue';
import { useConfirm } from '@/composables/useConfirm';

/**
 * The bar that appears once rows are selected.
 *
 * Docked to the bottom rather than replacing the page header, so the list stays readable while
 * you are choosing what to act on — the point of a bulk action is that you are still looking at
 * the rows.
 */
const props = defineProps({
    count: { type: Number, required: true },
    /**
     * `[{ label, tone, onSelect, confirm? }]`
     *
     * `confirm` is `{ title?, message?, confirmLabel?, tone? }`, or a function of the count
     * returning one. Every action is confirmed whether or not it supplies wording: a bulk
     * action changes many documents in one click, and it used to fire straight from the button.
     */
    actions: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
    /** What is selected, singular — "purchase order". */
    noun: { type: String, default: 'record' },
});

const emit = defineEmits(['clear']);

const { confirm } = useConfirm();

async function select(action) {
    if (props.busy) return;

    const count = props.count;
    const what = `${count} ${props.noun}${count === 1 ? '' : 's'}`;
    const copy = typeof action.confirm === 'function' ? action.confirm(count) : (action.confirm ?? {});
    const verb = action.label.replace(/ selected$/i, '');

    const agreed = await confirm({
        title: copy.title ?? `${verb} ${what}?`,
        message: copy.message ?? 'Each one is checked on its own; any that cannot be changed are left as they are and named afterwards.',
        confirmLabel: copy.confirmLabel ?? verb,
        cancelLabel: 'Back',
        tone: copy.tone ?? 'default',
    });

    if (agreed) action.onSelect();
}
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
                        :disabled="busy"
                        @click="select(action)"
                    >
                        <Icon v-if="action.icon" :name="action.icon" size="size-3.5" />
                        {{ action.label }}
                    </Button>
                </div>
            </div>
        </div>
    </Transition>
</template>
