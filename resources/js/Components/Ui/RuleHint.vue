<script setup>
import { computed, useId } from 'vue';
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
</script>

<template>
    <span class="group/rule relative inline-flex">
        <button
            type="button"
            class="inline-flex cursor-help text-ink-400 transition group-hover/rule:text-ink-600 focus-visible:text-ink-600 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
            aria-label="What this rule enforces"
            :aria-describedby="panelId"
            @click.prevent
        >
            <Icon name="info" :size="size" />
        </button>

        <span
            :id="panelId"
            class="pointer-events-none invisible absolute bottom-full left-1/2 z-40 mb-1.5 w-64 -translate-x-1/2 rounded-md bg-slate-900 px-3 py-2 text-left opacity-0 shadow-lg transition
                   group-hover/rule:visible group-hover/rule:opacity-100 group-focus-within/rule:visible group-focus-within/rule:opacity-100"
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
    </span>
</template>
