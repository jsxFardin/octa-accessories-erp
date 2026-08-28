<script setup>
/**
 * F-09 — what a contextual handoff says when the context did not survive the trip.
 *
 * A form reached as `/quotations/create?inquiry=999999` rendered blank and said nothing. That
 * is the *safe* behaviour and it stays: the server never preselects a record it could not
 * resolve, or one the user may not read. What was missing was the sentence explaining why the
 * screen is empty, so someone who arrived from a stale tab or a pasted link could tell the
 * difference between "nothing was carried across" and "this form is broken".
 *
 * The server decides the wording, because the server is the only party that knows whether the
 * record was missing or merely not this user's to read — and it deliberately says the same
 * thing either way, so the notice cannot be used to probe for records.
 */
import Icon from '@/Components/Ui/Icon.vue';
import Button from '@/Components/Ui/Button.vue';

defineProps({
    /** `{ tone, title, body, action_label, action_href }`, or null when there is nothing to say. */
    notice: { type: Object, default: null },
});

const TONES = {
    warning: 'border-amber-200 bg-amber-50 text-amber-900',
    info: 'border-brand-200 bg-brand-50 text-brand-900',
    danger: 'border-rose-200 bg-rose-50 text-rose-900',
};
</script>

<template>
    <!--
        `role="status"` rather than `alert`: this is a condition of the page as loaded, not an
        interruption, so it is announced without stealing focus.
    -->
    <div
        v-if="notice"
        role="status"
        class="flex items-start gap-2.5 rounded-lg border px-3 py-2.5 text-sm"
        :class="TONES[notice.tone] ?? TONES.warning"
    >
        <Icon name="warning" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />

        <div class="min-w-0 flex-1">
            <p class="font-medium">{{ notice.title }}</p>
            <p v-if="notice.body" class="mt-0.5">{{ notice.body }}</p>
        </div>

        <Button
            v-if="notice.action_href"
            size="sm"
            :href="notice.action_href"
            class="shrink-0"
        >{{ notice.action_label ?? 'Go back' }}</Button>
    </div>
</template>
