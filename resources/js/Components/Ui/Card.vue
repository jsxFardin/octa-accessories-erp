<script setup>
import RuleHint from '@/Components/Ui/RuleHint.vue';

defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    /**
     * Business rule or invariant reference. Shown as a small info marker, not a badge:
     * "BR-44" printed beside a title means nothing to a planner. The tooltip leads with
     * the rule in plain language; the code is the footnote for support conversations.
     */
    rule: { type: String, default: null },
    padded: { type: Boolean, default: true },
});
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <header
            v-if="title || $slots.actions"
            class="flex items-start justify-between gap-4 border-b border-slate-200 bg-slate-50/70 px-4 py-2.5"
        >
            <div class="min-w-0">
                <h2 class="flex items-center gap-1.5 text-sm font-semibold text-ink-800">
                    {{ title }}
                    <RuleHint v-if="rule" :rule="rule" />
                </h2>
                <p v-if="subtitle" class="mt-0.5 text-xs text-ink-500">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <div :class="padded ? 'p-4' : ''">
            <slot />
        </div>
    </section>
</template>
