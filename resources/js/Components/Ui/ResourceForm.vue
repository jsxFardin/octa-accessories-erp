<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';

/**
 * The master-data form pattern, written once (10-roadmap, Phase 0).
 *
 * `sections` describes the fields; the component owns validation display, dirty state and the
 * submit verb. A screen that needs more than this — a cost sheet, a planning board — is
 * bespoke and does not use it.
 */
const props = defineProps({
    /** `[{ title, rule, fields: [{ key, label, type, options, rule, hint, required, span }] }]` */
    sections: { type: Array, required: true },
    initial: { type: Object, default: () => ({}) },
    action: { type: String, required: true },
    method: { type: String, default: 'post' },
    submitLabel: { type: String, default: 'Save' },
    cancelHref: { type: String, default: null },
});

const defaults = computed(() => {
    const values = {};

    for (const section of props.sections) {
        for (const field of section.fields) {
            values[field.key] = props.initial?.[field.key]
                ?? (field.type === 'checkbox' ? false : field.default ?? '');
        }
    }

    return values;
});

const form = useForm(defaults.value);

function submit() {
    form[props.method](props.action, { preserveScroll: true });
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <Card v-for="section in sections" :key="section.title" :title="section.title" :rule="section.rule">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <FormField
                    v-for="field in section.fields"
                    :key="field.key"
                    :label="field.label"
                    :rule="field.rule"
                    :hint="field.hint"
                    :required="field.required"
                    :error="form.errors[field.key]"
                    :class="field.span === 'full' ? 'sm:col-span-2 lg:col-span-3' : ''"
                >
                    <label v-if="field.type === 'checkbox'" class="flex items-center gap-2 py-1.5 text-sm text-ink-700">
                        <input v-model="form[field.key]" type="checkbox" class="rounded border-slate-300">
                        {{ field.checkboxLabel ?? 'Yes' }}
                    </label>

                    <SelectInput
                        v-else-if="field.type === 'select'"
                        v-model="form[field.key]"
                        :options="field.options ?? []"
                        :value-key="field.valueKey ?? 'value'"
                        :label-key="field.labelKey ?? 'label'"
                        :error="form.errors[field.key]"
                    />

                    <textarea
                        v-else-if="field.type === 'textarea'"
                        v-model="form[field.key]"
                        rows="3"
                        class="form-textarea"
                    />

                    <TextInput
                        v-else
                        v-model="form[field.key]"
                        :type="field.type ?? 'text'"
                        :step="field.step"
                        :min="field.min"
                        :numeric="field.type === 'number'"
                        :error="form.errors[field.key]"
                    />
                </FormField>
            </div>
        </Card>

        <div class="flex items-center gap-2">
            <Button type="submit" variant="primary" :loading="form.processing">{{ submitLabel }}</Button>
            <Button v-if="cancelHref" :href="cancelHref">Cancel</Button>
            <span v-if="form.isDirty" class="text-xs text-amber-600">Unsaved changes</span>
        </div>
    </form>
</template>
