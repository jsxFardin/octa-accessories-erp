<script setup>
defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    /** Business rule or invariant reference, rendered small beside the title. */
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
                <h2 class="flex items-center gap-2 text-sm font-semibold text-ink-800">
                    {{ title }}
                    <span v-if="rule" class="rounded bg-slate-200 px-1.5 py-0.5 font-mono text-[10px] font-normal text-ink-700">
                        {{ rule }}
                    </span>
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
