<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
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
    <FormLayout @submit="submit">
        <!--
            Cards flow two-across on a wide screen rather than stacking down the middle of one.
            A master-data record is a handful of short sections; stacked, half of them sat below
            the fold with 1,000px of empty page beside them. The fields inside each card stay at
            two columns — a code in a 700px input is not easier to read, only wider.
        -->
        <div class="grid items-start gap-4 xl:grid-cols-2">
            <Card
                v-for="section in sections"
                :id="section.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')"
                :key="section.title"
                :title="section.title"
                :subtitle="section.description"
                :rule="section.rule"
                :class="section.span === 'full' ? 'xl:col-span-2' : ''"
            >
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
                        <!-- A checkbox aligns to the 36px control row so the grid stays level. -->
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
                    </FormField>
                </div>
            </Card>
        </div>

        <template #footer>
            <FormFooter :form="form" :label="submitLabel" :cancel-href="cancelHref" @save="submit" />
        </template>
    </FormLayout>
</template>
