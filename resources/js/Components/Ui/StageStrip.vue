<script setup>
import { pcs } from '@/plugins/formatting';

/**
 * A list's book as a strip: how many records stand at each stage, each tile a filter.
 *
 * Sales orders had this and the buying lists did not, so a buyer saw two rows and had no idea
 * whether two was everything or two of two hundred. The first tile may be an alert — "Late",
 * "Overdue" — the one count that is a problem by itself; it is red only while it is not zero.
 *
 * `stages`: `[{ key, label, count, active, tone? }]`, tone being 'danger' or 'warning'.
 */
defineProps({
    stages: { type: Array, required: true },
    /** What the strip counts, for a screen reader: "Purchase orders by stage". */
    label: { type: String, required: true },
});

defineEmits(['select']);

function tile(stage) {
    if (stage.active) return 'border-brand-400 bg-brand-50 ring-1 ring-brand-400';
    if (stage.tone === 'danger' && stage.count) return 'border-rose-200 bg-rose-50/60 hover:border-rose-300';

    return 'border-slate-200 bg-white hover:border-brand-300';
}

function figure(stage) {
    if (stage.tone === 'danger' && stage.count) return 'text-rose-700';
    if (stage.tone === 'warning' && stage.count) return 'text-amber-700';

    return stage.count ? 'text-ink-900' : 'text-ink-400';
}
</script>

<template>
    <nav :aria-label="label" class="mb-4 grid grid-cols-3 gap-2 sm:grid-cols-5" :class="stages.length > 8 ? 'xl:grid-cols-9' : stages.length > 5 ? 'xl:grid-cols-8' : ''">
        <button
            v-for="stage in stages"
            :key="stage.key"
            type="button"
            class="rounded-lg border px-3 py-2 text-left transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
            :class="tile(stage)"
            :aria-pressed="stage.active"
            :data-stage="stage.key"
            @click="$emit('select', stage.key)"
        >
            <span class="block text-xs" :class="stage.tone === 'danger' && stage.count ? 'text-rose-800' : 'text-ink-600'">{{ stage.label }}</span>
            <span class="mt-0.5 block text-lg leading-tight font-semibold tnum" :class="figure(stage)">{{ pcs(stage.count) }}</span>
        </button>
    </nav>
</template>
