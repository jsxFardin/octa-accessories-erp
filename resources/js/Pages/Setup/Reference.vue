<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { titleCase } from '@/plugins/formatting';

/**
 * One screen for every lookup list. The shape comes from the server's definition, so adding a
 * list is a definition in ReferenceRegistry rather than another near-identical page here.
 */
const props = defineProps({
    reference: { type: Object, required: true },
    rows: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const open = ref(false);
const editing = ref(null);

function blank() {
    return Object.fromEntries(
        props.reference.fields.map((field) => [field.name, field.default ?? (field.type === 'boolean' ? false : '')]),
    );
}

const form = useForm(blank());

function create() {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(row) {
    editing.value = row;

    const values = Object.fromEntries(
        props.reference.fields.map((field) => [
            field.name,
            field.type === 'boolean' ? Boolean(row[field.name]) : (row[field.name] ?? ''),
        ]),
    );

    form.defaults(values);
    form.reset();
    form.clearErrors();
    open.value = true;
}

function save() {
    const done = { onSuccess: () => (open.value = false), preserveScroll: true };

    editing.value
        ? form.put(`/setup/${props.reference.slug}/${editing.value.id}`, done)
        : form.post(`/setup/${props.reference.slug}`, done);
}

function remove(row) {
    if (!window.confirm(`Delete this ${props.reference.singular}? It cannot be undone.`)) return;

    router.delete(`/setup/${props.reference.slug}/${row.id}`, { preserveScroll: true });
}

// --- Rendering ---------------------------------------------------------------------------
/** Long text and hints belong in the form, not in a column that pushes the table sideways. */
const columns = computed(() => [
    ...props.reference.fields
        .filter((field) => field.type !== 'textarea')
        .map((field) => ({
            key: field.name,
            label: field.label,
            align: ['number', 'decimal'].includes(field.type) ? 'right' : undefined,
            sort: true,
            field,
        })),
]);

function optionLabel(field, value) {
    if (value === null || value === '') return '—';

    const match = (props.options[field.name] ?? []).find((option) => String(option.value) === String(value));

    return match?.label ?? value;
}

function rowActions(row) {
    return [
        { label: 'Edit', hidden: !props.can.update, onSelect: () => edit(row) },
        { label: 'Delete', tone: 'danger', hidden: !props.can.delete, onSelect: () => remove(row) },
    ];
}
</script>

<template>
    <AppLayout>
        <Head :title="reference.label" />

        <template #title>{{ reference.label }}</template>
        <template #subtitle>{{ reference.description }}</template>

        <template #actions>
            <Button href="/setup">
                <Icon name="left" size="size-3.5" />
                Setup
            </Button>
            <Button v-if="can.create" variant="primary" @click="create">
                <Icon name="add" size="size-3.5" />
                New {{ reference.singular }}
            </Button>
        </template>

        <Card :padded="false">
            <FilterBar
                v-if="reference.searchable"
                :filters="filters"
                :placeholder="`Search ${reference.label.toLowerCase()}…`"
            />

            <DataTable
                :columns="columns"
                :rows="rows"
                row-key="id"
                :actions="can.update || can.delete ? rowActions : null"
                dense
            >
                <template v-for="column in columns" :key="column.key" #[`cell:${column.key}`]="{ row, value }">
                    <span v-if="column.field.type === 'boolean'">
                        <Badge :tone="value ? 'success' : 'neutral'" :label="value ? 'Yes' : 'No'" />
                    </span>
                    <span v-else-if="column.field.type === 'reference'">
                        {{ optionLabel(column.field, value) }}
                    </span>
                    <span v-else-if="column.field.type === 'select'">
                        {{ value ? titleCase(value) : '—' }}
                    </span>
                    <span v-else-if="column.field.type === 'date'">
                        {{ $fmt.date(value) }}
                    </span>
                    <span v-else-if="['number', 'decimal'].includes(column.field.type)" class="tnum">
                        {{ value ?? '—' }}<span v-if="column.field.unit" class="ml-0.5 text-ink-400">{{ column.field.unit }}</span>
                    </span>
                    <span v-else :class="column.key === 'code' && 'font-medium text-ink-900'">
                        {{ value ?? '—' }}
                    </span>
                </template>

                <template #empty>
                    <EmptyState
                        :icon="reference.icon"
                        :title="`No ${reference.label.toLowerCase()} yet`"
                        :description="reference.description"
                        :action-label="can.create ? `New ${reference.singular}` : null"
                        :filtered="Boolean(filters.q)"
                        @action="create"
                        @clear-filters="router.get(`/setup/${reference.slug}`)"
                    />
                </template>
            </DataTable>
        </Card>

        <Modal
            v-model:open="open"
            :title="editing ? `Edit ${reference.singular}` : `New ${reference.singular}`"
            width="max-w-xl"
        >
            <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
                <FormField
                    v-for="field in reference.fields"
                    :key="field.name"
                    :label="field.label"
                    :hint="field.hint"
                    :error="form.errors[field.name]"
                    :class="['textarea'].includes(field.type) && 'sm:col-span-2'"
                >
                    <!-- A switch reads as a setting; a bare checkbox in a form grid reads as a mistake. -->
                    <button
                        v-if="field.type === 'boolean'"
                        type="button"
                        role="switch"
                        :aria-checked="form[field.name]"
                        class="relative h-5 w-9 rounded-full transition"
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
            </form>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="form.processing" @click="save">
                    {{ editing ? 'Save changes' : `Create ${reference.singular}` }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
