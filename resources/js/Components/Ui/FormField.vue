<script setup>
import { provide, toRef } from 'vue';

/**
 * A labelled field.
 *
 * Two things it does that a bare `<label>` + input does not:
 *
 *  1. The error is **provided down** to the control, so `TextInput` and `SelectInput` colour
 *     their own border without every page passing `:error` twice.
 *  2. The hint disappears while an error is showing. Stacking "Zero means no limit set" under
 *     "The credit limit field is required" gives the eye two things to read and one to act on.
 */
const props = defineProps({
    label: { type: String, default: null },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    required: { type: Boolean, default: false },
    /** The business rule this field's value feeds, shown beside it (README conventions). */
    rule: { type: String, default: null },
});

provide('fieldError', toRef(props, 'error'));
</script>

<template>
    <div class="min-w-0">
        <label v-if="label" class="mb-1 flex items-baseline justify-between gap-2">
            <span class="text-xs font-medium text-ink-600">
                {{ label }}<span v-if="required" class="ml-0.5 text-rose-500">*</span>
            </span>
            <span v-if="rule" class="shrink-0 font-mono text-[10px] font-normal text-ink-400">{{ rule }}</span>
        </label>

        <slot />

        <p v-if="error" class="mt-1 text-xs text-rose-600">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-xs leading-relaxed text-ink-500">{{ hint }}</p>
    </div>
</template>
