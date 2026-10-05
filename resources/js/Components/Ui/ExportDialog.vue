<script setup>
import { computed, ref, watch } from 'vue';
import Button from '@/Components/Ui/Button.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { describeFilters } from '@/composables/useListFilters';

/**
 * One export dialog for every list — never a bespoke export menu per screen.
 *
 * The point that matters is not the column picker but the query string: whatever the list was
 * filtered, searched and sorted by is carried into the download, so what arrives in the
 * spreadsheet is what the person was looking at. An export that quietly returns everything is
 * how a filtered view becomes a data leak.
 *
 * The format is a deliberate choice rather than a default nobody sees: CSV loses the leading
 * zero off every code the moment Excel opens it, and the people who wanted a document to sign
 * were printing the spreadsheet.
 */
const props = defineProps({
    /** Key from the server's ExportRegistry, e.g. `sales-orders`. */
    resource: { type: String, required: true },
});

const FORMATS = [
    { value: 'xlsx', label: 'Excel (.xlsx)', hint: 'Spreadsheet format for data analysis' },
    { value: 'pdf', label: 'PDF (.pdf)', hint: 'Document format for printing and signing' },
    { value: 'csv', label: 'CSV (.csv)', hint: 'Plain text, for another system to read' },
];

const open = ref(false);
const loading = ref(false);
const columns = ref([]);
const chosen = ref(new Set());
const format = ref('xlsx');

/** Set when the column list could not be fetched, so the dialog says so instead of showing nothing. */
const columnsFailed = ref(false);

/*
 * What the export is narrowed by, in the filter bar's own words. It used to print the query
 * string — "customer: 12", "sort: -total" — which nobody could check against what they meant.
 * Read when the dialog opens: the query string is not reactive.
 */
const activeFilters = ref([]);

/** A stored key as a heading: "credit_limit" → "Credit limit". */
function columnLabel(column) {
    const text = String(column).replace(/_/g, ' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}

async function show() {
    open.value = true;
    loading.value = true;
    columnsFailed.value = false;
    activeFilters.value = describeFilters([...new URLSearchParams(window.location.search).entries()]);

    try {
        const response = await fetch(`/exports/${props.resource}/columns`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) throw new Error(String(response.status));

        columns.value = (await response.json()).columns;
        chosen.value = new Set(columns.value);
    } catch {
        // An empty box looked like "this list has no columns". It means the list was not fetched.
        columns.value = [];
        chosen.value = new Set();
        columnsFailed.value = true;
    } finally {
        loading.value = false;
    }
}

function toggle(column) {
    const next = new Set(chosen.value);

    next.has(column) ? next.delete(column) : next.add(column);
    chosen.value = next;
}

function download() {
    const query = new URLSearchParams(window.location.search);

    query.delete('page');
    query.set('columns', [...chosen.value].join(','));
    query.set('format', format.value);

    // A real navigation, not fetch: the browser handles the file, and the streamed response
    // never has to be held in memory on either end.
    window.location.href = `/exports/${props.resource}?${query}`;
    open.value = false;
}

watch(open, (value) => {
    if (!value) columns.value = [];
});

defineExpose({ show });
</script>

<template>
    <Button size="sm" @click="show">
        <Icon name="download" size="size-3.5" />
        Export
    </Button>

    <Modal
        v-model:open="open"
        width="max-w-2xl"
        title="Export"
        subtitle="Every row that matches what the list is showing, on all its pages, in the columns you pick."
    >
        <div class="space-y-4">
            <div v-if="activeFilters.length" class="rounded-md bg-brand-50 px-3 py-2">
                <p class="text-xs font-medium text-brand-800">Only rows matching</p>
                <ul class="mt-1 space-y-0.5" data-export-filters>
                    <li v-for="filter in activeFilters" :key="filter.key" class="text-sm text-brand-900">
                        <span class="text-brand-800">{{ filter.label }}:</span> <span class="font-medium">{{ filter.value }}</span>
                    </li>
                </ul>
            </div>
            <p v-else class="text-xs text-ink-600">
                No filters are applied, so this exports the whole list.
            </p>

            <div class="rounded-md ring-1 ring-slate-200">
                <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2">
                    <p class="field-label mb-0">Columns</p>
                    <button
                        class="text-xs text-brand-700 hover:underline"
                        @click="chosen = new Set(chosen.size === columns.length ? [] : columns)"
                    >
                        {{ chosen.size === columns.length ? 'Clear all' : 'Select all' }}
                    </button>
                </div>

                <p v-if="loading" class="px-3 py-3 text-xs text-ink-600">Loading columns…</p>

                <p v-else-if="columnsFailed" class="px-3 py-3 text-sm text-rose-700" role="alert">
                    The list of columns could not be loaded, so nothing can be exported yet.
                    <button type="button" class="font-medium underline" @click="show">Try again</button>
                </p>

                <div v-else class="grid max-h-56 gap-1 overflow-y-auto p-2 sm:grid-cols-2">
                    <label
                        v-for="column in columns"
                        :key="column"
                        class="flex items-center gap-2 rounded px-1.5 py-1 text-sm text-ink-800 transition hover:bg-slate-50"
                    >
                        <input
                            type="checkbox"
                            class="form-checkbox"
                            :checked="chosen.has(column)"
                            @change="toggle(column)"
                        >
                        {{ columnLabel(column) }}
                    </label>
                </div>
            </div>

            <div>
                <p class="field-label">Download format</p>

                <div class="grid gap-2 sm:grid-cols-3">
                    <label
                        v-for="option in FORMATS"
                        :key="option.value"
                        class="cursor-pointer rounded-md px-3 py-2 ring-1 transition"
                        :class="format === option.value
                            ? 'bg-brand-50 ring-2 ring-brand-500'
                            : 'ring-slate-200 hover:bg-slate-50'"
                    >
                        <span class="flex items-center gap-2">
                            <input
                                v-model="format"
                                type="radio"
                                :value="option.value"
                                class="form-radio"
                            >
                            <span class="text-sm font-medium text-ink-900">{{ option.label }}</span>
                        </span>
                        <span class="mt-1 block text-xs leading-snug text-ink-500">{{ option.hint }}</span>
                    </label>
                </div>

                <p v-if="format === 'pdf'" class="mt-1.5 text-xs text-ink-500">
                    A PDF is laid out in one pass, so it stops at the first 2,000 rows. Use Excel or CSV for a full list.
                </p>
            </div>
        </div>

        <template #footer="{ close }">
            <p class="mr-auto text-xs text-ink-500">
                {{ chosen.size }} column{{ chosen.size === 1 ? '' : 's' }} selected
            </p>
            <Button @click="close">Cancel</Button>
            <Button variant="primary" :disabled="chosen.size === 0" @click="download">
                <Icon name="download" size="size-3.5" />
                Download
            </Button>
        </template>
    </Modal>
</template>
