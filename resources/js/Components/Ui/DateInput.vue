<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

/**
 * A date field with its own calendar.
 *
 * The native picker differs per browser, cannot be styled, and on Bengali locales renders a
 * different first-day-of-week than the factory's own calendars. This one is ours: ISO strings
 * in and out (`YYYY-MM-DD`), Monday-first, and typing still works because the trigger is a
 * real text input.
 *
 * Dates are formatted from local parts, never `toISOString()` — Dhaka is UTC+6, so a
 * `toISOString()` on a midnight date lands on the previous day.
 */
const model = defineModel({ type: [String, null], default: '' });

const props = defineProps({
    error: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    placeholder: { type: String, default: 'YYYY-MM-DD' },
    /** ISO strings. Out-of-range days are rendered but not selectable. */
    min: { type: String, default: null },
    max: { type: String, default: null },
    clearable: { type: Boolean, default: true },
});

const open = ref(false);
const wrapper = ref(null);
const field = ref(null);
const position = ref({ top: 0, left: 0 });
const cursor = ref(startOfMonth(parse(model.value) ?? new Date()));

const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

function iso(date) {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

function parse(value) {
    if (!value) return null;

    const [year, month, day] = String(value).slice(0, 10).split('-').map(Number);

    if (!year || !month || !day) return null;

    return new Date(year, month - 1, day);
}

function startOfMonth(date) {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}

const selected = computed(() => parse(model.value));

/** Six weeks, Monday first — a fixed grid so the popover never changes height. */
const days = computed(() => {
    const first = cursor.value;
    const offset = (first.getDay() + 6) % 7;
    const start = new Date(first.getFullYear(), first.getMonth(), 1 - offset);

    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index);

        return {
            date,
            iso: iso(date),
            outside: date.getMonth() !== first.getMonth(),
            disabled: (props.min && iso(date) < props.min) || (props.max && iso(date) > props.max),
        };
    });
});

const today = iso(new Date());

function place() {
    const rect = field.value?.getBoundingClientRect();

    if (!rect) return;

    const height = 320;
    const below = window.innerHeight - rect.bottom;

    position.value = {
        top: below < height ? Math.max(8, rect.top - height - 4) : rect.bottom + 4,
        left: Math.min(rect.left, window.innerWidth - 268),
    };
}

async function show() {
    if (props.disabled) return;

    open.value = true;
    cursor.value = startOfMonth(selected.value ?? new Date());

    await nextTick();
    place();
}

function pick(day) {
    if (day.disabled) return;

    model.value = day.iso;
    open.value = false;
}

function shiftMonth(delta) {
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + delta, 1);
}

function clear() {
    model.value = '';
    open.value = false;
}

function onKeydown(event) {
    if (event.key === 'Escape') open.value = false;
    if (event.key === 'ArrowDown' && !open.value) show();
}

function onDocumentClick(event) {
    if (!wrapper.value?.contains(event.target) && !event.target.closest('[data-date-popover]')) {
        open.value = false;
    }
}

watch(() => model.value, (value) => {
    const parsed = parse(value);

    if (parsed) cursor.value = startOfMonth(parsed);
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
    <div ref="wrapper" class="relative">
        <div class="relative">
            <!-- Typing stays possible: a planner entering thirty dates should never need the mouse. -->
            <input
                ref="field"
                v-model="model"
                type="text"
                inputmode="numeric"
                class="form-input pr-8"
                :class="error && 'form-input-error'"
                :placeholder="placeholder"
                :disabled="disabled"
                @focus="show"
                @keydown="onKeydown"
            >
            <button
                type="button"
                class="absolute inset-y-0 right-0 flex w-8 items-center justify-center text-ink-400 transition hover:text-ink-700"
                :disabled="disabled"
                aria-label="Open calendar"
                @click="open ? (open = false) : show()"
            >
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="4.5" width="14" height="12" rx="2" />
                    <path d="M3 8h14M7 3v3M13 3v3" stroke-linecap="round" />
                </svg>
            </button>
        </div>

        <Teleport to="body">
            <div
                v-if="open"
                data-date-popover
                class="fixed z-[60] w-[260px] rounded-md border border-slate-200 bg-white p-2 shadow-lg"
                :style="{ top: `${position.top}px`, left: `${position.left}px` }"
            >
                <div class="flex items-center justify-between px-1 pb-2">
                    <button
                        type="button"
                        class="rounded p-1 text-ink-500 transition hover:bg-slate-100"
                        aria-label="Previous month"
                        @click="shiftMonth(-1)"
                    >
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M12 5l-5 5 5 5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>

                    <p class="text-sm font-medium text-ink-900">
                        {{ MONTHS[cursor.getMonth()] }} {{ cursor.getFullYear() }}
                    </p>

                    <button
                        type="button"
                        class="rounded p-1 text-ink-500 transition hover:bg-slate-100"
                        aria-label="Next month"
                        @click="shiftMonth(1)"
                    >
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M8 5l5 5-5 5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </div>

                <div class="grid grid-cols-7 gap-0.5 text-center">
                    <span v-for="day in WEEKDAYS" :key="day" class="py-1 text-[10px] font-medium text-ink-400">
                        {{ day }}
                    </span>

                    <button
                        v-for="day in days"
                        :key="day.iso"
                        type="button"
                        class="rounded py-1 text-xs transition"
                        :class="[
                            day.iso === model
                                ? 'bg-brand-600 font-semibold text-white'
                                : day.iso === today
                                    ? 'bg-brand-50 font-medium text-brand-800'
                                    : day.outside
                                        ? 'text-ink-300 hover:bg-slate-50'
                                        : 'text-ink-800 hover:bg-slate-100',
                            day.disabled && 'cursor-not-allowed opacity-30 hover:bg-transparent',
                        ]"
                        :disabled="day.disabled"
                        @click="pick(day)"
                    >
                        {{ day.date.getDate() }}
                    </button>
                </div>

                <div class="mt-2 flex items-center justify-between border-t border-slate-100 pt-2">
                    <button
                        type="button"
                        class="rounded px-2 py-1 text-xs text-brand-700 transition hover:bg-brand-50"
                        @click="pick({ iso: today, disabled: (min && today < min) || (max && today > max) })"
                    >
                        Today
                    </button>
                    <button
                        v-if="clearable"
                        type="button"
                        class="rounded px-2 py-1 text-xs text-ink-500 transition hover:bg-slate-100"
                        @click="clear"
                    >
                        Clear
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
