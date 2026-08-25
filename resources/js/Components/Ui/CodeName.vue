<script setup>
/**
 * A code and its name, together.
 *
 * Vue's whitespace condensing strips the newline between two adjacent `<span>`s, so
 * `{{ code }}` / `{{ name }}` on separate template lines rendered as `FGFinished goods`.
 * One component guarantees the separator and gives every code+name pair in the app the
 * same treatment: mono code, muted name.
 */
defineProps({
    code: { type: [String, Number], default: null },
    name: { type: String, default: null },
});
</script>

<template>
    <span class="inline-flex min-w-0 items-baseline gap-1.5">
        <span v-if="code" class="shrink-0 font-medium text-ink-900">{{ code }}</span>
        <span v-if="name" class="truncate text-ink-500">{{ name }}</span>
    </span>
</template>
