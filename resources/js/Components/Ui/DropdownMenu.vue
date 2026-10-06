<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';

/**
 * The row-actions menu: a three-dot trigger with a small anchored menu.
 *
 * Rendered into `body` and positioned from the trigger's own rect, because a table with
 * `overflow-x: auto` clips an absolutely-positioned child — the menu on the last row would
 * otherwise open inside the scroll box and be unreachable.
 */
const props = defineProps({
    /** `[{ label, onSelect, tone?: 'default'|'danger', hidden?: boolean, disabled?: boolean }]` */
    items: { type: Array, default: () => [] },
    align: { type: String, default: 'right' },
    label: { type: String, default: 'Row actions' },
});

const open = ref(false);
const trigger = ref(null);
const menu = ref(null);
const position = ref({ top: 0, left: 0 });

const visibleItems = computed(() => props.items.filter((item) => !item.hidden));

async function toggle() {
    if (open.value) {
        open.value = false;

        return;
    }

    open.value = true;
    await nextTick();
    place();
    // Into the menu, on its first item: opening it used to leave focus on the three dots,
    // and the items — Edit, Delete — could then only be reached with a mouse.
    focusItem(0);
}

function enabledItems() {
    return [...(menu.value?.querySelectorAll('[role="menuitem"]:not([disabled])') ?? [])];
}

function focusItem(index) {
    const items = enabledItems();

    if (items.length) items[(index + items.length) % items.length].focus();
}

/** Close, and hand focus back to the button that opened the menu. */
function dismiss() {
    open.value = false;
    trigger.value?.focus();
}

function place() {
    const rect = trigger.value?.getBoundingClientRect();

    if (!rect) {
        return;
    }

    const width = 176;
    const height = visibleItems.value.length * 34 + 8;

    // Flip upwards when the menu would fall off the bottom of the viewport.
    const below = window.innerHeight - rect.bottom;

    position.value = {
        top: below < height ? rect.top - height - 4 : rect.bottom + 4,
        // Kept on the screen: on a phone the trigger can sit at the very edge of a scrolled table.
        left: Math.min(Math.max(8, props.align === 'right' ? rect.right - width : rect.left), window.innerWidth - width - 8),
    };
}

function select(item) {
    if (item.disabled) {
        return;
    }

    dismiss();
    item.onSelect?.();
}

function onDocumentClick(event) {
    if (!trigger.value?.contains(event.target) && !event.target.closest('[data-dropdown-menu]')) {
        open.value = false;
    }
}

function onKeydown(event) {
    if (!open.value) return;

    if (event.key === 'Escape') {
        // Closes the menu only — a panel behind it keeps its own Escape.
        event.stopPropagation();
        dismiss();

        return;
    }

    const items = enabledItems();
    const current = items.indexOf(document.activeElement);

    // Arrow keys walk the items and wrap; Home and End jump; Tab leaves and closes.
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        focusItem(current + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        focusItem(current <= 0 ? items.length - 1 : current - 1);
    } else if (event.key === 'Home') {
        event.preventDefault();
        focusItem(0);
    } else if (event.key === 'End') {
        event.preventDefault();
        focusItem(items.length - 1);
    } else if (event.key === 'Tab') {
        // The menu is drawn at the end of the page; Tab from inside it would land there.
        dismiss();
    }
}

function close() {
    open.value = false;
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown, true);
    window.addEventListener('resize', close);
    window.addEventListener('scroll', close, true);
});

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown, true);
    window.removeEventListener('resize', close);
    window.removeEventListener('scroll', close, true);
});
</script>

<template>
    <div class="inline-flex">
        <button
            ref="trigger"
            type="button"
            class="flex size-7 items-center justify-center rounded-md text-ink-600 transition hover:bg-slate-100 hover:text-ink-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
            :class="open && 'bg-slate-100 text-ink-900'"
            :aria-label="label"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click.stop="toggle"
        >
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <circle cx="10" cy="4" r="1.6" />
                <circle cx="10" cy="10" r="1.6" />
                <circle cx="10" cy="16" r="1.6" />
            </svg>
        </button>

        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-100"
                enter-from-class="opacity-0 scale-95"
                leave-active-class="transition duration-75"
                leave-to-class="opacity-0 scale-95"
            >
                <div
                    v-if="open"
                    ref="menu"
                    data-dropdown-menu
                    role="menu"
                    :aria-label="label"
                    class="fixed z-[90] w-44 origin-top-right rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                    :style="{ top: `${position.top}px`, left: `${position.left}px` }"
                >
                    <button
                        v-for="item in visibleItems"
                        :key="item.label"
                        type="button"
                        role="menuitem"
                        tabindex="-1"
                        class="block min-h-8 w-full px-3 py-1.5 text-left text-sm transition focus:outline-none disabled:cursor-not-allowed disabled:opacity-40"
                        :class="item.tone === 'danger'
                            ? 'text-rose-700 hover:bg-rose-50 focus:bg-rose-50'
                            : 'text-ink-700 hover:bg-slate-50 hover:text-ink-900 focus:bg-slate-100 focus:text-ink-900'"
                        :disabled="item.disabled"
                        @click="select(item)"
                    >
                        {{ item.label }}
                    </button>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
