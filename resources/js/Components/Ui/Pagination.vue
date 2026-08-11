<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SelectInput from '@/Components/Ui/SelectInput.vue';

const props = defineProps({
    meta: { type: Object, required: true },
});

const compact = defineModel('compact', { type: Boolean, default: false });

const PER_PAGE = [25, 50, 100, 200];

const perPage = computed(() => Number(new URLSearchParams(window.location.search).get('per_page') ?? 25));

/** The server already accepts `per_page` and clamps it; nothing rendered the control. */
function setPerPage(value) {
    const query = new URLSearchParams(window.location.search);

    query.set('per_page', String(value));
    query.delete('page');

    router.get(`${window.location.pathname}?${query}`, {}, { preserveState: true, preserveScroll: true, replace: true });
}

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
        class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-white px-3 py-2 text-xs text-ink-700"
    >
        <div class="flex items-center gap-3">
            <span class="tnum">{{ showing }}</span>

            <label class="hidden items-center gap-1 text-ink-500 sm:flex">
                Rows
                <!-- The same select as everywhere else, so the desk has one dropdown, not two. -->
                <SelectInput
                    class="w-20"
                    :model-value="perPage"
                    :options="PER_PAGE.map((size) => ({ value: size, label: String(size) }))"
                    :placeholder="null"
                    @update:model-value="setPerPage($event)"
                />
            </label>

            <button
                class="hidden items-center gap-1 text-ink-500 transition hover:text-ink-800 sm:flex"
                :title="compact ? 'Comfortable rows' : 'Compact rows'"
                @click="compact = !compact"
            >
                <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                    <path :d="compact ? 'M4 6h12M4 10h12M4 14h12' : 'M4 7h12M4 13h12'" stroke-linecap="round" />
                </svg>
                {{ compact ? 'Compact' : 'Comfortable' }}
            </button>
        </div>

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
                    link.active ? 'bg-brand-600 text-white' : 'text-ink-700 hover:bg-slate-100',
                    !link.url && 'cursor-default opacity-40',
                ]"
                v-html="link.label"
            />
        </nav>
    </div>
</template>
