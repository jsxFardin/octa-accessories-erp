<script setup>
import { computed, provide, toRef, useId } from 'vue';
import RuleHint from '@/Components/Ui/RuleHint.vue';

/**
 * A labelled field.
 *
 * Three things it does that a bare `<label>` + input does not:
 *
 *  1. The error is **provided down** to the control, so `TextInput` and `SelectInput` colour
 *     their own border without every page passing `:error` twice.
 *  2. The hint disappears while an error is showing. Stacking "Zero means no limit set" under
 *     "The credit limit field is required" gives the eye two things to read and one to act on.
 *  3. The label is **associated** with the control: an id is generated here and provided down,
 *     the inputs adopt it, and the error/hint paragraph is linked via `aria-describedby`.
 *     Without this every form in the app had a non-clickable label and an unlabelled input
 *     for screen readers.
 */
const props = defineProps({
    label: { type: String, default: null },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    required: { type: Boolean, default: false },
    /** The business rule this field's value feeds, shown beside it (README conventions). */
    rule: { type: String, default: null },
});

const fieldId = useId();
const describedBy = computed(() => (props.error || props.hint ? `${fieldId}-note` : null));

provide('fieldError', toRef(props, 'error'));
provide('fieldId', fieldId);
provide('fieldDescribedBy', describedBy);
provide('fieldRequired', toRef(props, 'required'));
</script>

<template>
    <div class="min-w-0">
        <label v-if="label" :for="fieldId" class="mb-1 flex items-baseline justify-between gap-2">
            <span class="text-xs font-medium text-ink-600">
                {{ label }}<span v-if="required" class="ml-0.5 text-rose-500" aria-hidden="true">*</span>
                <span v-if="required" class="sr-only">(required)</span>
            </span>
            <!-- Same treatment as Card: plain-language rule first, code as the footnote. -->
            <RuleHint v-if="rule" :rule="rule" size="size-3" class="shrink-0" />
        </label>

        <slot />

        <p v-if="error" :id="`${fieldId}-note`" role="alert" class="mt-1 text-xs text-rose-600">{{ error }}</p>
        <p v-else-if="hint" :id="`${fieldId}-note`" class="mt-1 text-xs leading-relaxed text-ink-500">{{ hint }}</p>
    </div>
</template>
