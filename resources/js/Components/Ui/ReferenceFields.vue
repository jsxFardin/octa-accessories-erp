<script setup>
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { titleCase } from '@/plugins/formatting';

/**
 * Renders a reference list's fields from its server-side definition.
 *
 * Shared by the Setup hub's inline cards and the full-list screen, so a lookup's form looks
 * and validates the same wherever it is opened.
 */
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
            :class="field.type === 'textarea' && 'sm:col-span-2'"
        >
            <!-- A switch reads as a setting; a bare checkbox in a form grid reads as a mistake. -->
            <button
                v-if="field.type === 'boolean'"
                type="button"
                role="switch"
                :aria-checked="form[field.name]"
                class="relative h-5 w-9 rounded-full transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                :class="form[field.name] ? 'bg-brand-600' : 'bg-slate-300'"
                @click="form[field.name] = !form[field.name]"
            >
                <span
                    class="absolute top-0.5 size-4 rounded-full bg-white shadow transition-all"
                    :class="form[field.name] ? 'left-4.5' : 'left-0.5'"
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
                :options="field.options.map((option) => ({ value: option, label: titleCase(option) }))"
                placeholder="— select —"
            />

            <DateInput v-else-if="field.type === 'date'" v-model="form[field.name]" />

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
