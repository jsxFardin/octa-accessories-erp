<script setup>
const model = defineModel({ type: [String, Number, null], default: '' });

defineProps({
    /** `[{ value, label }]` or `[{ id, name }]` — both shapes are accepted. */
    options: { type: Array, default: () => [] },
    error: { type: String, default: null },
    placeholder: { type: String, default: '—' },
    disabled: { type: Boolean, default: false },
    valueKey: { type: String, default: 'value' },
    labelKey: { type: String, default: 'label' },
});
</script>

<template>
    <select v-model="model" :disabled="disabled" class="form-select" :class="error && 'form-input-error'">
        <option v-if="placeholder" :value="''">{{ placeholder }}</option>
        <option
            v-for="option in options"
            :key="option[valueKey] ?? option.id"
            :value="option[valueKey] ?? option.id"
        >
            {{ option[labelKey] ?? option.name }}
        </option>
    </select>
</template>
