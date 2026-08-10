<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import Icon from '@/Components/Ui/Icon.vue';
import SlideOver from '@/Components/Ui/SlideOver.vue';
import ReferenceFields from '@/Components/Ui/ReferenceFields.vue';
import { titleCase } from '@/plugins/formatting';
import { useConfirm } from '@/composables/useConfirm';

/**
 * One screen for every lookup list. The shape comes from the server's definition, so adding a
 * list is a definition in ReferenceRegistry rather than another near-identical page here.
 */
const { confirm } = useConfirm();

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

async function remove(row) {
    if (!await confirm({
        title: `Delete this ${props.reference.singular}?`,
        message: 'Records already pointing at it will block the deletion.',
        confirmLabel: 'Delete',
    })) return;

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

        <SlideOver
            v-model:open="open"
            :title="editing ? `Edit ${reference.singular}` : `New ${reference.singular}`"
            width="max-w-xl"
        >
            <ReferenceFields :fields="reference.fields" :form="form" :options="options" />

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="form.processing" @click="save">
                    {{ editing ? 'Save changes' : `Create ${reference.singular}` }}
                </Button>
            </template>
        </SlideOver>
    </AppLayout>
</template>
