<script setup>
/**
 * The Print and PDF pair for a document, from one place.
 *
 * Both open in a new tab: printing is a detour, not a navigation, and losing the page you were
 * on to look at a PDF is how people stop using the button. `external` because the targets are
 * Blade, not Inertia — Inertia would fetch an HTML document it cannot mount.
 *
 * Whether the buttons appear at all comes from the `documents` shared prop, which is generated
 * from App\Support\Print\DocumentRegistry. Restating the status list here is exactly the drift
 * that produces a button the server then answers with a 403.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';

const props = defineProps({
    /** Registry key, e.g. `invoices`, `delivery-challans`. */
    document: { type: String, required: true },
    id: { type: [Number, String], required: true },
    /** Current status. Omit for a document with no status gate. */
    status: { type: String, default: null },
    size: { type: String, default: 'sm' },
});

const definition = computed(() => usePage().props.documents?.[props.document] ?? null);

const withheld = computed(
    () => props.status !== null && (definition.value?.withheld ?? []).includes(props.status),
);

const base = computed(() => `/${definition.value?.segment}/${props.id}`);
</script>

<template>
    <template v-if="definition && !withheld">
        <Button :size="size" :href="`${base}/print`" external target="_blank">Print</Button>
        <Button :size="size" :href="`${base}/pdf`" external target="_blank">PDF</Button>
    </template>
</template>
