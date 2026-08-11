<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

/**
 * The one select in the system, and it is searchable.
 *
 * Item and product lists here run to thousands of rows; a native `<select>` makes a store
 * keeper scroll for a lot number they can already spell. The public API is unchanged from the
 * native version it replaced, so every existing `<SelectInput v-model … :options>` became
 * searchable without touching the pages.
 *
 * The popover is teleported to `body` and positioned from the trigger's rect: a line-item
 * table scrolls on both axes, and an absolutely-positioned child of a cell gets clipped.
 */
const model = defineModel({ type: [String, Number, null], default: '' });

const props = defineProps({
    /** `[{ value, label }]` or `[{ id, name }]` — both shapes are accepted. */
    options: { type: Array, default: () => [] },
    error: { type: String, default: null },
    /** Falsy (or `null`) removes the "clear" row: the field then has no empty state. */
    placeholder: { type: String, default: '—' },
    disabled: { type: Boolean, default: false },
    valueKey: { type: String, default: 'value' },
    labelKey: { type: String, default: 'label' },
    /** Secondary line under each option — a name beside a code, a customer beside an order. */
    hintKey: { type: String, default: null },
    /**
     * Options needed before the filter box appears. Zero — always — because a list that is
     * short today is long once a factory has been running a year, and a person who has learned
     * to type into one dropdown should not find the next one silently refusing.
     */
    searchThreshold: { type: Number, default: 0 },
});

const inheritedError = inject('fieldError', null);
const invalid = computed(() => Boolean(props.error ?? inheritedError?.value));

const open = ref(false);
const query = ref('');
const activeIndex = ref(0);
const trigger = ref(null);
const searchBox = ref(null);
const listBox = ref(null);
const position = ref({ top: 0, left: 0, width: 0, placement: 'below' });

function valueOf(option) {
    return option[props.valueKey] ?? option.id;
}

function labelOf(option) {
    return String(option[props.labelKey] ?? option.name ?? '');
}

function hintOf(option) {
    return props.hintKey ? (option[props.hintKey] ?? null) : null;
}

const selected = computed(
    () => props.options.find((option) => String(valueOf(option)) === String(model.value)) ?? null,
);

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (!needle) return props.options;

    // Matching the hint too is what makes "Nordic" find CUST-001.
    return props.options.filter((option) => {
        const haystack = `${labelOf(option)} ${hintOf(option) ?? ''}`.toLowerCase();

        return haystack.includes(needle);
    });
});

const showSearch = computed(() => props.options.length >= props.searchThreshold);

function place() {
    const rect = trigger.value?.getBoundingClientRect();

    if (!rect) return;

    const height = Math.min(288, filtered.value.length * 34 + (showSearch.value ? 44 : 8) + 8);
    const below = window.innerHeight - rect.bottom;

    position.value = {
        top: below < height + 8 ? rect.top - height - 4 : rect.bottom + 4,
        left: Math.min(rect.left, window.innerWidth - rect.width - 8),
        width: rect.width,
        placement: below < height + 8 ? 'above' : 'below',
    };
}

async function toggle() {
    if (props.disabled) return;

    if (open.value) {
        close();

        return;
    }

    open.value = true;
    query.value = '';
    activeIndex.value = Math.max(0, filtered.value.findIndex((option) => option === selected.value));

    await nextTick();
    place();
    searchBox.value?.focus();
}

function close() {
    open.value = false;
    query.value = '';
}

function choose(option) {
    model.value = option === null ? '' : valueOf(option);
    close();
    trigger.value?.focus();
}

function move(delta) {
    if (!filtered.value.length) return;

    activeIndex.value = (activeIndex.value + delta + filtered.value.length) % filtered.value.length;
    scrollActiveIntoView();
}

async function scrollActiveIntoView() {
    await nextTick();
    listBox.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
}

function onKeydown(event) {
    if (!open.value) {
        if (['Enter', ' ', 'ArrowDown'].includes(event.key)) {
            event.preventDefault();
            toggle();
        }

        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const option = filtered.value[activeIndex.value];

        if (option) choose(option);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        // Stopped here: a slide-over listens for Escape too, and one press should shut the
        // list, not the panel the list is being filled in.
        event.stopPropagation();
        close();
        trigger.value?.focus();
    } else if (event.key === 'Tab') {
        close();
    }
}

function onDocumentClick(event) {
    if (!trigger.value?.contains(event.target) && !event.target.closest('[data-select-popover]')) {
        close();
    }
}

watch(query, () => {
    activeIndex.value = 0;
    place();
});

onMounted(() => {
    document.addEventListener('click', onDocumentClick, true);
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
});

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick, true);
    window.removeEventListener('resize', place);
    window.removeEventListener('scroll', place, true);
});
</script>

<template>
    <div class="relative">
        <button
            ref="trigger"
            type="button"
            class="form-select flex w-full items-center justify-between gap-2 text-left"
            :class="[invalid && 'form-input-error', disabled && 'cursor-not-allowed opacity-60']"
            :disabled="disabled"
            role="combobox"
            :aria-expanded="open"
            aria-haspopup="listbox"
            @click="toggle"
            @keydown="onKeydown"
        >
            <span class="truncate" :class="selected ? 'text-ink-900' : 'text-ink-400'">
                {{ selected ? labelOf(selected) : (placeholder || '—') }}
            </span>
            <svg class="size-4 shrink-0 text-ink-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                data-select-popover
                class="fixed z-[90] overflow-hidden rounded-md border border-slate-200 bg-white shadow-lg"
                :style="{ top: `${position.top}px`, left: `${position.left}px`, minWidth: `${Math.max(position.width, 180)}px` }"
            >
                <div v-if="showSearch" class="border-b border-slate-100 p-1.5">
                    <input
                        ref="searchBox"
                        v-model="query"
                        type="text"
                        class="w-full rounded border border-slate-200 px-2 py-1 text-sm text-ink-900 placeholder:text-ink-400 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none"
                        placeholder="Type to filter…"
                        @keydown="onKeydown"
                    >
                </div>

                <div ref="listBox" role="listbox" class="max-h-64 overflow-y-auto py-1">
                    <button
                        v-if="placeholder"
                        type="button"
                        class="block w-full px-3 py-1.5 text-left text-sm text-ink-400 hover:bg-slate-50"
                        @click="choose(null)"
                    >
                        {{ placeholder }}
                    </button>

                    <button
                        v-for="(option, index) in filtered"
                        :key="valueOf(option)"
                        type="button"
                        role="option"
                        :data-active="index === activeIndex"
                        :aria-selected="option === selected"
                        class="block w-full px-3 py-1.5 text-left text-sm"
                        :class="[
                            index === activeIndex ? 'bg-brand-50' : 'hover:bg-slate-50',
                            option === selected ? 'font-medium text-brand-800' : 'text-ink-800',
                        ]"
                        @click="choose(option)"
                        @mousemove="activeIndex = index"
                    >
                        <span class="block truncate">{{ labelOf(option) }}</span>
                        <span v-if="hintOf(option)" class="block truncate text-[11px] text-ink-500">
                            {{ hintOf(option) }}
                        </span>
                    </button>

                    <p v-if="filtered.length === 0" class="px-3 py-4 text-center text-xs text-ink-500">
                        <template v-if="query">Nothing matches “{{ query }}”.</template>
                        <!-- An empty list is a missing lookup row, not a failed search. -->
                        <template v-else>Nothing to choose from yet.</template>
                    </p>
                </div>
            </div>
        </Teleport>
    </div>
</template>
