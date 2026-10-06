<script setup>
import { computed } from 'vue';

/**
 * A twelve-point trend beside a figure. Decorative in the accessibility tree: the figure
 * and its delta carry the meaning, the line only shows the shape of the last weeks.
 */
const props = defineProps({
    values: { type: Array, required: true },
    width: { type: Number, default: 96 },
    height: { type: Number, default: 28 },
});

const PAD = 3;

const points = computed(() => {
    const values = props.values.map(Number);
    const max = Math.max(...values, 0);
    const min = Math.min(...values, 0);
    const span = max - min || 1;
    const step = values.length > 1 ? (props.width - PAD * 2) / (values.length - 1) : 0;

    return values.map((value, index) => ({
        x: PAD + index * step,
        y: PAD + (props.height - PAD * 2) * (1 - (value - min) / span),
    }));
});

// Coordinates rounded to a tenth of a pixel: geometry, not a figure anyone reads.
const px = (n) => Math.round(n * 10) / 10;
const path = computed(() => points.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${px(p.x)} ${px(p.y)}`).join(' '));
const last = computed(() => points.value.at(-1));
const flat = computed(() => props.values.every((v) => Number(v) === 0));
</script>

<template>
    <svg
        :width="width"
        :height="height"
        :viewBox="`0 0 ${width} ${height}`"
        class="shrink-0"
        :class="flat ? 'text-slate-300' : 'text-brand-500'"
        aria-hidden="true"
        focusable="false"
    >
        <path :d="path" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
        <!-- Current week: a marker with a surface ring, so it stays legible where the line crosses it. -->
        <circle v-if="last" :cx="last.x" :cy="last.y" r="3.5" fill="currentColor" stroke="#fff" stroke-width="2" />
    </svg>
</template>
