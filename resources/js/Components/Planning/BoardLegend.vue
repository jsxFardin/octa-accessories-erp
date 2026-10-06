<script setup>
import { computed } from 'vue';

/**
 * One legend for the board, whichever way it is being read.
 *
 * Reading and placing used to carry a legend each, and the colours changed meaning between
 * them: green was "loaded" in one and "has the hours" in the other. The colours now mean one
 * thing — green has room, amber is tight, red is over or not enough, grey is closed — and
 * only the words follow the mode.
 */
const props = defineProps({
    placing: { type: Boolean, default: false },
});

const SWATCHES = {
    room: 'bg-emerald-50 ring-emerald-300',
    tight: 'bg-amber-100 ring-amber-300',
    over: 'bg-rose-100 ring-rose-300',
    closed: 'bg-slate-100 ring-slate-300',
};

const entries = computed(() => (props.placing
    ? [
        ['room', 'Has the hours'],
        ['tight', 'Fits, but fills the day past 85%'],
        ['over', 'Not enough — placing there asks for a reason'],
        ['closed', 'Holiday, or before the step ahead of it finishes'],
    ]
    : [
        ['room', 'Has room'],
        ['tight', '85% full or more'],
        ['over', 'Over capacity'],
        ['closed', 'Holiday'],
    ]));
</script>

<template>
    <ul class="flex flex-wrap items-center gap-x-4 gap-y-1" aria-label="What the colours mean">
        <li v-for="[key, label] in entries" :key="key" class="inline-flex items-center gap-1.5">
            <span class="size-3 rounded ring-1" :class="SWATCHES[key]" aria-hidden="true" />
            {{ label }}
        </li>
    </ul>
</template>
