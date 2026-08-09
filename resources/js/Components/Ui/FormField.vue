<script setup>
defineProps({
    label: { type: String, default: null },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    required: { type: Boolean, default: false },
    /** The business rule this field's value feeds, shown beside it (README conventions). */
    rule: { type: String, default: null },
});
</script>

<template>
    <div>
        <label v-if="label" class="field-label flex items-center justify-between gap-2">
            <span>
                {{ label }}
                <span v-if="required" class="text-rose-500">*</span>
            </span>
            <span v-if="rule" class="font-mono text-[10px] font-normal text-slate-400">{{ rule }}</span>
        </label>

        <slot />

        <p v-if="error" class="mt-1 text-xs text-rose-600">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
    </div>
</template>
