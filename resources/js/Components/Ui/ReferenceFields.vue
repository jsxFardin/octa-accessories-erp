<script setup>
import Button from '@/Components/Ui/Button.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Icon from '@/Components/Ui/Icon.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { titleCase } from '@/plugins/formatting';

/**
 * Renders a reference list's fields from its server-side definition.
 *
 * Shared by the Setup hub's inline cards and the full-list screen, so a lookup's form looks
 * and validates the same wherever it is opened.
 */
/**
 * A vocabulary written as a code (`centre_fold`) is title-cased for the eye; one that is
 * already prose — a country name — is left exactly as it is, because "Côte d’Ivoire" comes
 * back out of the title-caser spelled differently to how it goes in.
 */
function optionLabel(option) {
    return /^[a-z0-9_]+$/.test(String(option)) ? titleCase(option) : String(option);
}

/* --- a list field: one row per entry, add one, take one away ------------------------------ */
function listOf(form, field) {
    if (!Array.isArray(form[field.name])) form[field.name] = [];

    return form[field.name];
}

function addEntry(form, field) {
    listOf(form, field).push('');
}

function removeEntry(form, field, index) {
    listOf(form, field).splice(index, 1);
}

defineProps({
    /** Field definitions from ReferenceRegistry. */
    fields: { type: Array, required: true },
    /** An Inertia `useForm` instance holding one key per field. */
    form: { type: Object, required: true },
    /** `{ fieldName: [{ value, label, hint }] }` for `reference` fields. */
    options: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <FormField
            v-for="field in fields"
            :key="field.name"
            :label="field.label"
            :hint="field.hint"
            :error="form.errors[field.name]"
            :class="['textarea', 'list'].includes(field.type) && 'sm:col-span-2'"
        >
            <!-- A switch reads as a setting; a bare checkbox in a form grid reads as a mistake. -->
            <button
                v-if="field.type === 'boolean'"
                type="button"
                role="switch"
                :aria-checked="form[field.name]"
                :aria-label="field.label"
                class="relative h-6 w-11 rounded-full transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:ring-offset-2 focus-visible:outline-none"
                :class="form[field.name] ? 'bg-brand-600' : 'bg-slate-400'"
                @click="form[field.name] = !form[field.name]"
            >
                <span
                    class="absolute top-0.5 size-5 rounded-full bg-white shadow transition-all"
                    :class="form[field.name] ? 'left-5.5' : 'left-0.5'"
                />
            </button>

            <SelectInput
                v-else-if="field.type === 'reference'"
                v-model="form[field.name]"
                :options="options[field.name] ?? []"
                hint-key="hint"
                :placeholder="(field.rules ?? []).includes('required') ? '— select —' : '— none —'"
            />

            <SelectInput
                v-else-if="field.type === 'select'"
                v-model="form[field.name]"
                :options="field.options.map((option) => ({ value: option, label: optionLabel(option) }))"
                placeholder="— select —"
            />

            <DateInput v-else-if="field.type === 'date'" v-model="form[field.name]" />

            <!-- A list of choices as rows, not as text the clerk has to punctuate. -->
            <div v-else-if="field.type === 'list'" class="space-y-2">
                <div v-for="(entry, index) in listOf(form, field)" :key="index" class="flex items-start gap-2">
                    <div class="min-w-0 flex-1">
                        <TextInput v-model="listOf(form, field)[index]" :aria-label="`${field.label} ${index + 1}`" :placeholder="`Choice ${index + 1}`" />
                        <p v-if="form.errors[`${field.name}.${index}`]" class="mt-1 text-xs text-rose-700">{{ form.errors[`${field.name}.${index}`] }}</p>
                    </div>
                    <Button type="button" variant="ghost" size="sm" :aria-label="`Remove choice ${index + 1}`" @click="removeEntry(form, field, index)">
                        <Icon name="remove" size="size-3.5" />
                    </Button>
                </div>
                <Button type="button" variant="secondary" size="sm" @click="addEntry(form, field)">
                    <Icon name="add" size="size-3.5" />
                    Add {{ listOf(form, field).length ? 'another' : 'a' }} choice
                </Button>
            </div>

            <textarea
                v-else-if="field.type === 'textarea'"
                v-model="form[field.name]"
                rows="2"
                class="form-textarea"
            />

            <div v-else class="flex items-center gap-2">
                <TextInput
                    v-model="form[field.name]"
                    :type="field.type === 'time' ? 'time' : (['number', 'decimal'].includes(field.type) ? 'number' : 'text')"
                    :step="field.step ?? (field.type === 'decimal' ? '0.01' : undefined)"
                    :numeric="['number', 'decimal'].includes(field.type)"
                />
                <span v-if="field.unit" class="shrink-0 text-xs text-ink-500">{{ field.unit }}</span>
            </div>
        </FormField>
    </div>
</template>
