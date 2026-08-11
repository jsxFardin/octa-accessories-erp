<script setup>
import FormPage from '@/Components/Ui/FormPage.vue';

/**
 * The shape every document form takes: the work in the main column, and what you check while
 * doing it in a rail beside it.
 *
 * Forms used to be capped at 768px in the middle of a 1600px page, which read as an unfinished
 * screen — and on a line-item document it was actively worse, because the table that is the
 * point of the screen had less room than the empty margins either side of it. The width now
 * goes to a second column of content: totals, readiness, notes. Those are the things somebody
 * looks back at while typing line eleven, and below 1280px they stack under the form rather
 * than competing with it.
 *
 * The rail sticks: a long order should not make you scroll back up to see what it is worth.
 */
defineProps({
    /** Widen the rail for a panel that carries a table rather than a list of figures. */
    wideRail: { type: Boolean, default: false },
});

const emit = defineEmits(['submit']);
</script>

<template>
    <FormPage full>
        <form
            class="grid items-start gap-4"
            :class="$slots.rail ? (wideRail ? 'xl:grid-cols-[minmax(0,1fr)_26rem]' : 'xl:grid-cols-[minmax(0,1fr)_20rem]') : ''"
            @submit.prevent="emit('submit')"
        >
            <div class="min-w-0 space-y-4">
                <slot />
            </div>

            <aside v-if="$slots.rail" class="space-y-4 xl:sticky xl:top-20">
                <slot name="rail" />
            </aside>
        </form>

        <slot name="footer" />
    </FormPage>
</template>
