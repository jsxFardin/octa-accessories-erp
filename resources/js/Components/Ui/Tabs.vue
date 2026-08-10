<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * Underlined tab strip.
 *
 * Tabs are links carrying a `?tab=` parameter rather than local state, so a tab is a URL
 * someone can send to a colleague and a browser back button behaves the way it looks like it
 * should. `only` keeps the visit partial where the page supports it.
 */
defineProps({
    /** `[{ key, label, href, count }]` */
    tabs: { type: Array, required: true },
    current: { type: String, required: true },
});
</script>

<template>
    <div class="border-b border-slate-200">
        <nav class="-mb-px flex flex-wrap gap-1" aria-label="Tabs">
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                preserve-scroll
                class="flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                :class="tab.key === current
                    ? 'border-brand-600 font-medium text-brand-700'
                    : 'border-transparent text-ink-600 hover:border-slate-300 hover:text-ink-900'"
                :aria-current="tab.key === current ? 'page' : undefined"
            >
                {{ tab.label }}
                <span
                    v-if="tab.count !== undefined"
                    class="rounded-full px-1.5 py-0.5 text-[10px] tnum"
                    :class="tab.key === current ? 'bg-brand-100 text-brand-800' : 'bg-slate-100 text-ink-500'"
                >
                    {{ tab.count }}
                </span>
            </Link>
        </nav>
    </div>
</template>
