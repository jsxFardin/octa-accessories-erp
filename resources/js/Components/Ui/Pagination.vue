<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    meta: { type: Object, required: true },
});

const showing = computed(() => {
    const { from, to, total } = props.meta;

    if (!total) return 'No results';

    return `${from}–${to} of ${total.toLocaleString('en-GB')}`;
});

const hasPages = computed(() => (props.meta.links?.length ?? 0) > 3);
</script>

<template>
    <div
        v-if="meta.total"
        class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-white px-3 py-2 text-xs text-slate-600"
    >
        <span class="tnum">{{ showing }}</span>

        <nav v-if="hasPages" class="flex flex-wrap items-center gap-1">
            <component
                :is="link.url ? Link : 'span'"
                v-for="link in meta.links"
                :key="link.label"
                :href="link.url ?? undefined"
                preserve-scroll
                preserve-state
                class="min-w-7 rounded px-2 py-1 text-center"
                :class="[
                    link.active ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100',
                    !link.url && 'cursor-default opacity-40',
                ]"
                v-html="link.label"
            />
        </nav>
    </div>
</template>
