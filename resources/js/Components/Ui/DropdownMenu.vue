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
        left: props.align === 'right' ? rect.right - width : rect.left,
    };
}

function select(item) {
    if (item.disabled) {
        return;
    }

    open.value = false;
    item.onSelect?.();
}

function onDocumentClick(event) {
    if (!trigger.value?.contains(event.target) && !event.target.closest('[data-dropdown-menu]')) {
        open.value = false;
    }
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        open.value = false;
    }
}

function close() {
    open.value = false;
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
    window.addEventListener('resize', close);
    window.addEventListener('scroll', close, true);
});

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
    window.removeEventListener('resize', close);
    window.removeEventListener('scroll', close, true);
});
</script>

<template>
    <div class="inline-flex">
        <button
            ref="trigger"
            class="flex size-7 items-center justify-center rounded-md text-ink-500 transition hover:bg-slate-100 hover:text-ink-800"
            :class="open && 'bg-slate-100 text-ink-800'"
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
                    data-dropdown-menu
                    role="menu"
                    class="fixed z-[60] w-44 origin-top-right rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                    :style="{ top: `${position.top}px`, left: `${position.left}px` }"
                >
                    <button
                        v-for="item in visibleItems"
                        :key="item.label"
                        role="menuitem"
                        class="block w-full px-3 py-1.5 text-left text-sm transition disabled:cursor-not-allowed disabled:opacity-40"
                        :class="item.tone === 'danger'
                            ? 'text-rose-600 hover:bg-rose-50'
                            : 'text-ink-700 hover:bg-slate-50 hover:text-ink-900'"
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
