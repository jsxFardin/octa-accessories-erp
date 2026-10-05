<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useBookedRate } from '@/composables/useBookedRate';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { resolveDefaults } from '@/plugins/formDefaults';

/**
 * The master-data form pattern, written once (10-roadmap, Phase 0).
 *
 * `sections` describes the fields; the component owns validation display, dirty state and the
 * submit verb. A screen that needs more than this — a cost sheet, a planning board — is
 * bespoke and does not use it.
 */
const props = defineProps({
    /**
     * `[{ title, rule, fields: [{ key, label, type, options, rule, hint, required, span, disabled }] }]`
     *
     * `options` may be a function of the form — `(form) => [...]` — for a list that depends on
     * another field. When the list changes and no longer holds the chosen value, the value is cleared.
     */
    sections: { type: Array, required: true },
    initial: { type: Object, default: () => ({}) },
    action: { type: String, required: true },
    method: { type: String, default: 'post' },
    submitLabel: { type: String, default: 'Save' },
    cancelHref: { type: String, default: null },
    /**
     * Pass the currency list (`[{ id, is_base, reference_rate }]`) on a form that has
     * `currency_id` and `exchange_rate` fields: the rate is then filled from the rate on file
     * when the currency changes, and the rate field is hidden for the base currency.
     */
    rateCurrencies: { type: Array, default: null },
    /** The record already exists, so the rate it was booked at is kept when the form opens. */
    existing: { type: Boolean, default: false },
});

const form = useForm(resolveDefaults(props.sections, props.initial));

const bookedRate = props.rateCurrencies
    ? useBookedRate(form, () => props.rateCurrencies, { existing: props.existing })
    : null;

/** The rate field has nothing to ask while the document is in the base currency. */
function visible(field) {
    return !(bookedRate && field.key === 'exchange_rate' && bookedRate.isBase.value);
}

function hintOf(field) {
    return (bookedRate && field.key === 'exchange_rate' ? bookedRate.rateHint.value : null) ?? field.hint;
}

/** A select's choices: fixed, or worked out from what else is on the form. */
function optionsOf(field) {
    return (typeof field.options === 'function' ? field.options(form) : field.options) ?? [];
}

/*
 * A dependent list that no longer offers the chosen value drops it. Left alone, the field kept
 * showing nothing while the form went on sending the old id — a brand of the previous customer.
 */
for (const field of props.sections.flatMap((section) => section.fields)) {
    if (typeof field.options !== 'function') continue;

    watch(() => optionsOf(field), (options) => {
        const value = form[field.key];

        if (value === '' || value === null || value === undefined) return;

        const key = field.valueKey ?? 'value';

        if (!options.some((option) => String(option?.[key] ?? option) === String(value))) form[field.key] = '';
    }, { immediate: true });
}

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
                        v-for="field in section.fields.filter(visible)"
                        :key="field.key"
                        :label="field.label"
                        :rule="field.rule"
                        :hint="hintOf(field)"
                        :required="field.required"
                        :error="form.errors[field.key]"
                        :class="field.span === 'full' ? 'sm:col-span-2' : ''"
                    >
                        <!-- A checkbox aligns to the 36px control row so the grid stays level. -->
                        <label v-if="field.type === 'checkbox'" class="flex h-9 items-center gap-2 text-sm text-ink-700">
                            <input v-model="form[field.key]" type="checkbox" class="form-checkbox">
                            {{ field.checkboxLabel ?? 'Yes' }}
                        </label>

                        <SelectInput
                            v-else-if="field.type === 'select'"
                            v-model="form[field.key]"
                            :options="optionsOf(field)"
                            :disabled="field.disabled ?? false"
                            :value-key="field.valueKey ?? 'value'"
                            :label-key="field.labelKey ?? 'label'"
                            :hint-key="field.hintKey ?? null"
                            :code-key="field.codeKey ?? 'code'"
                            :error="form.errors[field.key]"
                        />

                        <textarea
                            v-else-if="field.type === 'textarea'"
                            v-model="form[field.key]"
                            rows="3"
                            class="form-textarea"
                        />

                        <DateInput
                            v-else-if="field.type === 'date'"
                            v-model="form[field.key]"
                            :error="form.errors[field.key]"
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
