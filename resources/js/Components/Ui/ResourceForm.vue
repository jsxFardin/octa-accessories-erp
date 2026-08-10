<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormPage from '@/Components/Ui/FormPage.vue';
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
    <FormPage :sections="sections.map((section) => section.title)">
        <form class="space-y-4" @submit.prevent="submit">
            <Card
                v-for="section in sections"
                :id="section.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')"
                :key="section.title"
                :title="section.title"
                :subtitle="section.description"
                :rule="section.rule"
            >
                <!--
                    Two columns, not three. A form field wants to be about 360px: wide enough
                    for a customer name, narrow enough that the label and the caret are in one
                    glance.
                -->
                <div class="grid gap-x-4 gap-y-3 sm:grid-cols-2">
                    <FormField
                        v-for="field in section.fields"
                        :key="field.key"
                        :label="field.label"
                        :rule="field.rule"
                        :hint="field.hint"
                        :required="field.required"
                        :error="form.errors[field.key]"
                        :class="field.span === 'full' ? 'sm:col-span-2' : ''"
                    >
                        <label v-if="field.type === 'checkbox'" class="flex h-9 items-center gap-2 text-sm text-ink-700">
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

                        <!-- A checkbox aligns to the 36px control row so the grid stays level. -->
                    </FormField>
                </div>
            </Card>

            <FormFooter :form="form" :label="submitLabel" :cancel-href="cancelHref" @save="submit" />
        </form>
    </FormPage>
</template>
