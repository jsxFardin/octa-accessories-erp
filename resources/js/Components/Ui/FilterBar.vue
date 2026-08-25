<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import Icon from '@/Components/Ui/Icon.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import DateInput from '@/Components/Ui/DateInput.vue';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    /** `[{ key, label, options: [{value,label}], type }]` */
    fields: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Search…' },
});

const state = ref({ ...props.filters });

/**
 * Filters live in the query string, not in component state, so a filtered list is a URL
 * someone can send to a colleague — which is how half the questions in a factory get asked.
 *
 * The query is built by *merging into* the current URL rather than rebuilding from state:
 * `per_page` (and anything else the bar does not manage) is not in `listingFilters()`, so
 * rebuilding silently threw it away — set 200 rows, type one character, back to 25.
 */
const push = useDebounceFn(() => {
    const query = new URLSearchParams(window.location.search);

    query.delete('page'); // a changed filter restarts at page 1

    Object.entries(state.value).forEach(([key, value]) => {
        if (value === '' || value === null || value === undefined) {
            query.delete(key);
        } else {
            query.set(key, String(value));
        }
    });

    // A no-op push (mount-time date normalisation, prop resync) must not cost a request.
    if (query.toString() === new URLSearchParams(window.location.search).toString()) return;

    router.get(`${window.location.pathname}?${query}`, {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}, 250);

watch(state, push, { deep: true });

/**
 * Re-sync when the server's idea of the filters changes underneath us — browser Back, or a
 * partial reload that altered them. Without this the chips and inputs show yesterday's values
 * and the next edit pushes stale state back to the server.
 */
watch(
    () => props.filters,
    (value) => {
        const next = { ...value };
        if (JSON.stringify(next) !== JSON.stringify(state.value)) state.value = next;
    },
);

/**
 * What is currently narrowing the list, as removable chips. Without this a user who filtered
 * yesterday reopens the URL today and wonders where their records went — `sort` is excluded
 * because ordering hides nothing.
 */
const chips = computed(() =>
    Object.entries(state.value)
        .filter(([key, value]) => value !== '' && value !== null && value !== undefined && key !== 'sort')
        .map(([key, value]) => {
            if (key === 'q') {
                return { key, label: 'Search', value: `“${value}”` };
            }

            const field = props.fields.find((candidate) => candidate.key === key);
            const option = field?.options?.find((row) => String(row.value) === String(value));

            return {
                key,
                label: field?.label ?? key,
                value: option?.label ?? value,
            };
        }),
);

function clearOne(key) {
    state.value = { ...state.value, [key]: '' };
}

function reset() {
    // Clear the filters, keep the view: sort order and per_page are not filters and losing
    // them on "Clear all" reads as the page misbehaving.
    state.value = Object.fromEntries(
        Object.keys(state.value)
            .filter((key) => key !== 'sort')
            .map((key) => [key, '']),
    );
}
</script>

<template>
    <div class="border-b border-slate-200 bg-white">
        <div class="flex flex-wrap items-center gap-2 px-3 py-2">
            <!-- Fixed width: a search box that eats the whole row makes the filters beside it
                 look like an afterthought, which is how the old bar read. -->
            <div class="relative w-full sm:w-72">
                <Icon name="search" size="size-3.5" class="pointer-events-none absolute top-2.5 left-2.5 text-ink-400" />
                <input
                    v-model="state.q"
                    type="search"
                    :placeholder="placeholder"
                    :aria-label="placeholder || 'Search this list'"
                    class="form-input pl-7.5"
                >
            </div>

            <div v-for="field in fields" :key="field.key" :class="field.type === 'date' ? 'w-36' : 'w-40'">
                <DateInput
                    v-if="field.type === 'date'"
                    v-model="state[field.key]"
                    :placeholder="field.label"
                />
                <!-- A customer filter over 400 customers is unusable without a search box. -->
                <SelectInput
                    v-else
                    v-model="state[field.key]"
                    :options="field.options"
                    :placeholder="`All ${field.label.toLowerCase()}`"
                />
            </div>

            <slot />
        </div>

        <div v-if="chips.length" class="flex flex-wrap items-center gap-1.5 border-t border-slate-100 px-3 py-1.5">
            <span class="text-[10px] font-medium tracking-wider text-ink-400 uppercase">Filtered by</span>

            <button
                v-for="chip in chips"
                :key="chip.key"
                class="group inline-flex items-center gap-1 rounded-full bg-brand-50 py-0.5 pr-1 pl-2 text-[11px] text-brand-800 transition hover:bg-brand-100"
                @click="clearOne(chip.key)"
            >
                <span class="text-brand-600">{{ chip.label }}:</span>
                <span class="font-medium">{{ chip.value }}</span>
                <Icon name="close" size="size-3" class="text-brand-400 group-hover:text-brand-700" />
            </button>

            <button class="ml-1 text-[11px] text-ink-500 transition hover:text-ink-800 hover:underline" @click="reset">
                Clear all
            </button>
        </div>
    </div>
</template>
