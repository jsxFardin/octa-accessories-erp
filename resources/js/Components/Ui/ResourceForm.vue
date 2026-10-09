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
import { getPath, setPath } from '@/plugins/formPath';

/**
 * The master-data form pattern, written once (10-roadmap, Phase 0).
 *
 * `sections` describes the fields; the component owns validation display, dirty state and the
 * submit verb. A screen that needs more than this — a cost sheet, a planning board — is
 * bespoke and does not use it.
 */
const props = defineProps({
    /**
     * `[{ title, rule, when, fields: [{ key, label, type, options, rule, hint, required, span, disabled, when }] }]`
     *
     * `options` may be a function of the form — `(form) => [...]` — for a list that depends on
     * another field. When the list changes and no longer holds the chosen value, the value is cleared.
     *
     * `when` — on a field or a whole section — is a predicate of the form, `(form) => bool`: a
     * tool's cavity count has nothing to ask of a yarn. A field that disappears is also cleared,
     * so a service item does not post the cavities typed while it was still a tool.
     *
     * A key may be dotted (`attributes.diameter_mm`): the value is posted nested under
     * `attributes`, where a family's specification attributes live.
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
    /**
     * One column of sections read top to bottom, instead of two columns of cards.
     *
     * Two columns suit a handful of short sections. A long form — the item master is seven
     * sections, some of which grow when a family is chosen — turns into a masonry of uneven
     * cards with holes beside the short ones, and the eye loses the order the work is done in.
     * Stacked, the sections read in sequence and the rail beside them carries the summary.
     */
    stacked: { type: Boolean, default: false },
    /**
     * `columns`: two assigned columns on a wide screen, one below it.
     *
     * Each section says which column it sits in (`column: 'left' | 'right'`), so the page is
     * two deliberate stacks — what the thing is on the left, how it is handled on the right —
     * rather than a masonry of whatever fits. Below 1536px the two stacks become one, in the
     * same order, so the reading order never changes. A rail still fits beside both.
     */
    layout: { type: String, default: null, validator: (value) => [null, 'grid', 'stacked', 'columns'].includes(value) },
});

const mode = props.layout ?? (props.stacked ? 'stacked' : 'grid');

/**
 * The visible sections, in the column they asked for.
 *
 * A section that names no column alternates: first left, second right, and so on. Each column
 * is its own stack, so a short card never leaves a hole beside a tall one — which is what a
 * row-aligned grid did under every two-column form.
 */
function sectionsIn(column) {
    const visible = props.sections.filter(sectionVisible);

    return visible.filter((section, index) => (section.column ?? (index % 2 === 0 ? 'left' : 'right')) === column);
}

const form = useForm(resolveDefaults(props.sections, props.initial));

const bookedRate = props.rateCurrencies
    ? useBookedRate(form, () => props.rateCurrencies, { existing: props.existing })
    : null;

/** The rate field has nothing to ask while the document is in the base currency. */
function visible(field) {
    if (bookedRate && field.key === 'exchange_rate' && bookedRate.isBase.value) return false;

    return typeof field.when === 'function' ? Boolean(field.when(form)) : true;
}

function sectionVisible(section) {
    return typeof section.when === 'function' ? Boolean(section.when(form)) : true;
}

function valueOf(field) {
    return getPath(form, field.key);
}

function setValue(field, value) {
    setPath(form, field.key, value);
}

/** A multi-checkbox holds the chosen values as a list. */
function toggleChoice(field, choice, checked) {
    const current = Array.isArray(valueOf(field)) ? valueOf(field) : [];
    const next = checked ? [...new Set([...current, choice])] : current.filter((value) => value !== choice);

    setValue(field, next);
}

/*
 * A field that is no longer asked is also no longer sent. Left alone, the cavities typed while
 * an item was a tool would ride along once it became a service, and the server would refuse a
 * field the user could not see.
 */
for (const section of props.sections) {
    for (const field of section.fields) {
        if (typeof field.when !== 'function' && typeof section.when !== 'function') continue;

        watch(() => sectionVisible(section) && visible(field), (shown, wasShown) => {
            if (shown || wasShown === undefined) return;

            setValue(field, field.type === 'checkbox' ? false : field.type === 'checkboxes' ? [] : '');
        });
    }
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
        const value = getPath(form, field.key);

        if (value === '' || value === null || value === undefined) return;

        const key = field.valueKey ?? 'value';

        if (!options.some((option) => String(option?.[key] ?? option) === String(value))) setPath(form, field.key, '');
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

            A long form asks for `stacked` instead, and puts what the reader checks while typing
            in the rail.
        -->
        <div
            class="grid items-start gap-4"
            :class="{ 'grid-cols-1 max-w-3xl': mode === 'stacked', 'grid-cols-1 xl:grid-cols-2': mode === 'grid', 'grid-cols-1 2xl:grid-cols-2': mode === 'columns' }"
        >
            <template v-for="column in (mode === 'stacked' ? [null] : ['left', 'right'])" :key="column ?? 'all'">
            <div :class="column ? 'min-w-0 space-y-4' : 'contents'">
            <Card
                v-for="section in (column ? sectionsIn(column) : sections.filter(sectionVisible))"
                :id="section.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')"
                :key="section.title"
                :title="section.title"
                :subtitle="typeof section.description === 'function' ? section.description(form) : section.description"
                :rule="section.rule"
                :class="''"
            >
                <!-- A section with nothing to ask yet says what would fill it, rather than sitting empty. -->
                <p v-if="section.empty && section.fields.filter(visible).length === 0" class="text-sm text-ink-600">
                    {{ typeof section.empty === 'function' ? section.empty(form) : section.empty }}
                </p>

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
                            <input type="checkbox" class="form-checkbox" :checked="Boolean(valueOf(field))" :disabled="field.disabled ?? false" @change="setValue(field, $event.target.checked)">
                            {{ field.checkboxLabel ?? 'Yes' }}
                        </label>

                        <!-- Several choices that may all apply: the variant axes of an item. -->
                        <div v-else-if="field.type === 'checkboxes'" class="flex min-h-9 flex-wrap items-center gap-x-4 gap-y-1 text-sm text-ink-700">
                            <label v-for="choice in optionsOf(field)" :key="choice.value" class="inline-flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    class="form-checkbox"
                                    :checked="(valueOf(field) ?? []).includes(choice.value)"
                                    :disabled="field.disabled ?? false"
                                    @change="toggleChoice(field, choice.value, $event.target.checked)"
                                >
                                {{ choice.label }}
                            </label>
                        </div>

                        <SelectInput
                            v-else-if="field.type === 'select'"
                            :model-value="valueOf(field)"
                            @update:model-value="setValue(field, $event)"
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
                            :value="valueOf(field)"
                            @input="setValue(field, $event.target.value)"
                            rows="3"
                            class="form-textarea"
                            :class="field.disabled && 'cursor-not-allowed opacity-60'"
                            :disabled="field.disabled ?? false"
                        />

                        <DateInput
                            v-else-if="field.type === 'date'"
                            :model-value="valueOf(field)"
                            @update:model-value="setValue(field, $event)"
                            :disabled="field.disabled ?? false"
                            :error="form.errors[field.key]"
                        />

                        <TextInput
                            v-else
                            :model-value="valueOf(field)"
                            @update:model-value="setValue(field, $event)"
                            :type="field.type ?? 'text'"
                            :step="field.step"
                            :min="field.min"
                            :numeric="field.type === 'number'"
                            :disabled="field.disabled ?? false"
                            :error="form.errors[field.key]"
                        />
                    </FormField>
                </div>
            </Card>
            </div>
            </template>
        </div>

        <!-- What the reader checks while typing: a summary, a preview, the consequences of saving. -->
        <template v-if="$slots.rail" #rail>
            <slot name="rail" :form="form" />
        </template>

        <template #footer>
            <FormFooter :form="form" :label="submitLabel" :cancel-href="cancelHref" @save="submit" />
        </template>
    </FormLayout>
</template>
