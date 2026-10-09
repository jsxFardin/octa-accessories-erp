<script setup>
import { computed } from 'vue';
import Card from '@/Components/Ui/Card.vue';
import Badge from '@/Components/Ui/Badge.vue';
import { summarise } from '@/Pages/MasterData/Items/itemSections';

/**
 * What is about to be saved, read back in the reader's words while they type — and what
 * happens next, so "Create" is not a leap.
 */
const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, required: true },
    forProduct: { type: Boolean, default: false },
    isEdit: { type: Boolean, default: false },
});

const summary = computed(() => summarise(props.form, props.options, { forProduct: props.forProduct }));

const family = computed(() => (props.options.families ?? []).find((option) => String(option.value) === String(props.form.production_family_id)));

/** `BC-06-00012` is assigned on save; the family decides the first two parts. */
const codePreview = computed(() => {
    if (props.form.code) return props.form.code;
    if (family.value) return `${family.value.prefix}-${family.value.code}-…`;

    return props.forProduct ? 'PL-02-…' : 'ITM-00-…';
});

const rows = computed(() => [
    ['Kind', summary.value.kind],
    ['Family', summary.value.family],
    ['Made for', summary.value.madeFor],
    ['Units', summary.value.units],
    ['Varies by', summary.value.variants],
].filter(([, value]) => value));
</script>

<template>
    <Card :title="isEdit ? 'This item' : 'What you are creating'">
        <div class="space-y-3">
            <div>
                <p class="truncate text-sm font-semibold text-ink-900">{{ summary.name ?? 'Unnamed item' }}</p>
                <p class="mt-0.5 flex items-center gap-2 text-xs text-ink-600">
                    <span class="font-mono tnum">{{ codePreview }}</span>
                    <Badge v-if="!isEdit" status="draft" />
                </p>
            </div>

            <dl v-if="rows.length" class="space-y-1.5 border-t border-slate-100 pt-3 text-sm">
                <div v-for="[label, value] in rows" :key="label" class="flex justify-between gap-3">
                    <dt class="shrink-0 text-ink-500">{{ label }}</dt>
                    <dd class="min-w-0 text-right text-ink-800">{{ value }}</dd>
                </div>
            </dl>

            <p v-if="!isEdit" class="border-t border-slate-100 pt-3 text-xs leading-5 text-ink-600">
                Saved as a draft. <template v-if="forProduct">Its page then lists the specification, artwork, bill of materials and routing it needs before it can be activated and quoted.</template><template v-else>It can be activated once its warehouse, stock levels and standard cost are set.</template>
            </p>
        </div>
    </Card>
</template>
