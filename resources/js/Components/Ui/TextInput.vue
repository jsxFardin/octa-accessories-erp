<script setup>
import { computed, inject } from 'vue';

const model = defineModel({ type: [String, Number], default: '' });

const props = defineProps({
    type: { type: String, default: 'text' },
    error: { type: String, default: null },
    placeholder: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    step: { type: [String, Number], default: null },
    min: { type: [String, Number], default: null },
    max: { type: [String, Number], default: null },
    /** Right-align and tabular-figure numeric fields so columns of digits line up. */
    numeric: { type: Boolean, default: false },
    /** Inside a line-item table: borderless until hovered or focused. */
    cell: { type: Boolean, default: false },
});

// The surrounding FormField already knows; a page should not have to say it twice.
const inheritedError = inject('fieldError', null);
const invalid = computed(() => Boolean(props.error ?? inheritedError?.value));
</script>

<template>
    <input
        v-model="model"
        :type="type"
        :placeholder="placeholder"
        :disabled="disabled"
        :step="step"
        :min="min"
        :max="max"
        :class="[
            cell ? 'cell-input' : 'form-input',
            invalid && 'form-input-error',
            numeric && 'text-right tnum',
        ]"
        :aria-invalid="invalid || undefined"
    >
</template>
