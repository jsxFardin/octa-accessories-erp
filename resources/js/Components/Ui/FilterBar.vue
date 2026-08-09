<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';

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
 */
const push = useDebounceFn(() => {
    const query = Object.fromEntries(
        Object.entries(state.value).filter(([, value]) => value !== '' && value !== null && value !== undefined),
    );

    router.get(window.location.pathname, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}, 250);

watch(state, push, { deep: true });

function reset() {
    state.value = {};
}
</script>

<template>
    <div class="flex flex-wrap items-end gap-2 border-b border-slate-200 bg-white px-3 py-2">
        <div class="min-w-48 flex-1">
            <input
                v-model="state.q"
                type="search"
                :placeholder="placeholder"
                class="form-input"
            >
        </div>

        <div v-for="field in fields" :key="field.key" class="w-40">
            <label class="field-label">{{ field.label }}</label>
            <select v-model="state[field.key]" class="form-select">
                <option value="">All</option>
                <option v-for="option in field.options" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
        </div>

        <slot />

        <button
            v-if="Object.values(state).some((v) => v)"
            class="rounded-md px-2 py-1.5 text-xs text-slate-500 hover:bg-slate-100"
            @click="reset"
        >
            Clear
        </button>
    </div>
</template>
