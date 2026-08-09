<script setup>
const model = defineModel({ type: [String, Number], default: '' });

defineProps({
    type: { type: String, default: 'text' },
    error: { type: String, default: null },
    placeholder: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    step: { type: [String, Number], default: null },
    min: { type: [String, Number], default: null },
    max: { type: [String, Number], default: null },
    /** Right-align and tabular-figure numeric fields so columns of digits line up. */
    numeric: { type: Boolean, default: false },
});
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
        class="form-input"
        :class="[error && 'form-input-error', numeric && 'text-right tnum']"
    >
</template>
