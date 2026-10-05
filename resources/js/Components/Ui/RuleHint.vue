<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import Icon from '@/Components/Ui/Icon.vue';
import { ruleSentences } from '@/plugins/rules';

/**
 * The info marker for a business-rule reference.
 *
 * A planner reads what the rule does; the code is the footnote for support calls. The
 * tooltip is real markup shown on hover *and* keyboard focus — a native `title` never
 * fires on focus and has no touch equivalent — and the trigger points at the panel with
 * `aria-describedby`, so a screen reader hears the sentences, not just the code.
 */
const props = defineProps({
    /** e.g. "BR-1 · BR-44" or "Gate 2 · I5" */
    rule: { type: String, required: true },
    size: { type: String, default: 'size-3.5' },
});

const panelId = useId();
const sentences = computed(() => ruleSentences(props.rule));

const trigger = ref(null);
const panel = ref(null);
const shown = ref(false);
/** Set by a tap or click, so the tooltip stays up on a device with no hover. */
const pinned = ref(false);
const position = ref({ top: 0, left: 0 });

const WIDTH = 256;
const GAP = 6;
const EDGE = 8;

/**
 * The panel is rendered in `body` and placed with fixed coordinates. It used to be an
 * absolutely-positioned child of the marker, and the markers live in card headers — inside a
 * `Card`, which clips its overflow. The top lines of every explanation were cut off exactly
 * where they are used most.
 */
async function place() {
    await nextTick();

    const anchor = trigger.value?.getBoundingClientRect();

    if (!anchor) return;

    const height = panel.value?.offsetHeight ?? 0;
    const above = anchor.top - GAP - height;

    position.value = {
        // Above the marker when there is room, below it when there is not.
        top: above >= EDGE ? above : anchor.bottom + GAP,
        left: Math.min(
            Math.max(EDGE, anchor.left + anchor.width / 2 - WIDTH / 2),
            Math.max(EDGE, window.innerWidth - WIDTH - EDGE),
        ),
    };
}

function show() {
    shown.value = true;
    place();
}

function hide() {
    if (pinned.value) return;

    shown.value = false;
}

function dismiss() {
    pinned.value = false;
    shown.value = false;
}

function toggle() {
    pinned.value = !pinned.value;

    pinned.value ? show() : dismiss();
}

function onKeydown(event) {
    if (event.key === 'Escape' && shown.value) dismiss();
}

function onOutside(event) {
    if (pinned.value && !trigger.value?.contains(event.target)) dismiss();
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('click', onOutside);
    // A fixed panel does not travel with the page; follow it rather than leave it stranded.
    window.addEventListener('scroll', dismiss, true);
    window.addEventListener('resize', dismiss);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('click', onOutside);
    window.removeEventListener('scroll', dismiss, true);
    window.removeEventListener('resize', dismiss);
});
</script>

<template>
    <span class="inline-flex">
        <button
            ref="trigger"
            type="button"
            class="inline-flex cursor-help text-ink-400 transition hover:text-ink-600 focus-visible:text-ink-600 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
            aria-label="What this rule enforces"
            :aria-describedby="panelId"
            :aria-expanded="shown"
            @mouseenter="show"
            @mouseleave="hide"
            @focus="show"
            @blur="dismiss"
            @click.prevent="toggle"
        >
            <Icon name="info" :size="size" />
        </button>

        <Teleport to="body">
            <span
                :id="panelId"
                ref="panel"
                class="pointer-events-none fixed z-[90] w-64 rounded-md bg-slate-900 px-3 py-2 text-left shadow-lg transition-opacity"
                :class="shown ? 'visible opacity-100' : 'invisible opacity-0'"
                :style="{ top: `${position.top}px`, left: `${position.left}px` }"
                role="tooltip"
            >
                <span v-if="sentences.length" class="block space-y-1">
                    <span v-for="sentence in sentences" :key="sentence" class="block text-xs leading-relaxed font-normal text-white">
                        {{ sentence }}
                    </span>
                </span>
                <span v-else class="block text-xs leading-relaxed font-normal text-white">
                    Enforced by an internal rule.
                </span>
                <span class="mt-1 block font-mono text-[10px] font-normal text-slate-400">{{ rule }}</span>
            </span>
        </Teleport>
    </span>
</template>
