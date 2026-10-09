<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Ui/Icon.vue';
import Sparkline from '@/Components/Ui/Sparkline.vue';
import { number } from '@/plugins/formatting';

/**
 * One figure with its context: label, value, what it is compared against, and the shape of
 * the last weeks. The value is proportional (not tabular) because it stands alone; the
 * delta says which way is good, so a rising number is never green by default.
 */
const props = defineProps({
    label: { type: String, required: true },
    /** Already formatted — the page decides whether it is pieces, money or a percentage. */
    value: { type: String, required: true },
    /** One short line under the value: a count, a breakdown, or why the value is empty. */
    sub: { type: String, default: null },
    /**
     * `{ value, unit, vs, upIsGood }` — value is signed and may be null (no earlier period);
     * unit is '%' or ' pts'; vs names the comparison period.
     */
    delta: { type: Object, default: null },
    /** Twelve weekly numbers for the sparkline. */
    trend: { type: Array, default: null },
    /** 'neutral' | 'danger' | 'warning' — a tone only when the figure itself is a problem. */
    tone: { type: String, default: 'neutral' },
    href: { type: String, default: null },
});

const TONES = {
    neutral: { card: 'border-slate-200 bg-white hover:border-brand-300', value: 'text-ink-900' },
    danger: { card: 'border-rose-200 bg-rose-50/60 hover:border-rose-300', value: 'text-rose-700' },
    warning: { card: 'border-amber-200 bg-amber-50/60 hover:border-amber-300', value: 'text-amber-700' },
};

const tone = computed(() => TONES[props.tone] ?? TONES.neutral);

const deltaView = computed(() => {
    const delta = props.delta;

    if (!delta) return null;
    // Nothing to compare against yet: the row says so once, under the tiles.
    if (delta.value === null || delta.value === undefined) {
        return null;
    }

    const value = Number(delta.value);
    const sign = value > 0 ? '+' : '';
    const good = value === 0 ? null : (value > 0) === (delta.upIsGood !== false);

    return {
        text: `${sign}${number(value, 1)}${delta.unit ?? '%'} vs ${delta.vs}`,
        class: good === null ? 'text-ink-500' : good ? 'text-emerald-700' : 'text-rose-700',
        icon: value === 0 ? null : value > 0 ? 'up' : 'down',
    };
});
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href"
        class="group flex min-h-28 flex-col rounded-lg border p-3.5 shadow-sm transition"
        :class="[tone.card, href && 'hover:-translate-y-px hover:shadow-md focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none']"
    >
        <p class="flex items-start justify-between gap-2 text-xs leading-snug text-ink-600">
            <span>{{ label }}</span>
            <!-- The chevron is the promise: this figure opens the list behind it. -->
            <Icon v-if="href" name="right" size="size-3.5" class="mt-px shrink-0 text-ink-400 transition group-hover:translate-x-0.5 group-hover:text-brand-500" />
        </p>

        <!-- The sparkline wraps beneath the value on a narrow tile rather than squeezing it. -->
        <div class="mt-1.5 flex flex-wrap items-end justify-between gap-x-3 gap-y-1.5">
            <div class="min-w-0">
                <p class="text-2xl leading-none font-semibold whitespace-nowrap" :class="tone.value">{{ value }}</p>
                <p v-if="sub" class="mt-1.5 text-xs leading-snug text-ink-500">{{ sub }}</p>
            </div>
            <Sparkline v-if="trend && trend.length" :values="trend" class="mb-0.5" />
        </div>

        <p v-if="deltaView" class="mt-2 flex items-center gap-1 text-xs leading-snug" :class="deltaView.class">
            <Icon v-if="deltaView.icon" :name="deltaView.icon" size="size-3" aria-hidden="true" />
            {{ deltaView.text }}
        </p>
    </component>
</template>
