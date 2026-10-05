<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import LineItemsTable from '@/Components/Ui/LineItemsTable.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { qty } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';

const props = defineProps({
    product: { type: Object, required: true },
    spec: { type: Object, default: null },
    /** The draft being corrected, or null when this is a new version. */
    bom: { type: Object, default: null },
    /** The version a new one starts as a copy of: the newest, whatever its status. */
    basedOn: { type: Object, default: null },
    /** The lines the form opens with. */
    activeLines: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    uoms: { type: Array, default: () => [] },
});

function blankLine() {
    return { item_id: '', uom_id: '', qty_per_base: '', wastage_pct: 0, colour_index: '', is_optional: false };
}

/**
 * BR-1 — quantities are per `base_qty` finished pieces, 1000 by default, because everything in
 * this business is quoted and consumed per thousand.
 */
const form = useForm({
    product_spec_id: props.spec?.id ?? '',
    base_qty: Number(props.bom?.base_qty ?? props.basedOn?.base_qty ?? 1000),
    notes: props.bom?.notes ?? '',
    lines: props.activeLines.length
        ? props.activeLines.map((line) => ({ ...line }))
        : [blankLine()],
    // PD-3 — activating supersedes whatever is active now, in the same transaction. Off by
    // default: a BOM drafted to price an option should not replace the one production runs.
    activate: false,
});

/** Activation is its own permission; the server refuses the checkbox without it. */
const canActivate = can('bom.activate');

const itemsById = computed(() => Object.fromEntries(props.items.map((item) => [String(item.id), item])));

/** The item's own base unit, unless someone deliberately picks another. */
function onItemChange(line) {
    const item = itemsById.value[String(line.item_id)];

    if (item && !line.uom_id) {
        line.uom_id = item.base_uom_id;
    }
}

const lineColumns = [
    { key: 'item_id', label: 'Material', required: true },
    { key: 'uom_id', label: 'Unit', width: '8rem', required: true },
    { key: 'qty_per_base', label: 'Qty per base unit', width: '10rem', align: 'right', required: true },
    { key: 'wastage_pct', label: 'Wastage %', width: '8rem', align: 'right' },
    { key: 'colour_index', label: 'Colour', width: '10rem' },
    { key: 'is_optional', label: 'Optional', width: '6rem' },
    { key: 'scaled', label: 'For 30,000', width: '9rem', align: 'right', errorKeys: [] },
];

function addLine() {
    form.lines = [...form.lines, blankLine()];
}

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
}

/** What one job of `trial` pieces would draw — the same scaling MRP and the job card use. */
const trial = 30000;

function scaled(line) {
    return ((Number(line.qty_per_base) || 0) * trial) / (Number(form.base_qty) || 1);
}

const colourOptions = computed(() =>
    Array.from({ length: Number(props.spec?.colours) || 0 }, (_, index) => ({
        value: index + 1,
        label: props.spec?.colour_list?.[index]?.name || `Colour ${index + 1}`,
    })),
);

const isEdit = computed(() => props.bom !== null);

const STATUS_WORDS = { draft: 'a draft', active: 'the active version', superseded: 'superseded' };

function submit() {
    if (isEdit.value) form.put(`/boms/${props.bom.id}`);
    else form.post(`/products/${props.product.id}/boms`);
}
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit BOM v${bom.version_no} — ${product.code}` : `New BOM — ${product.code}`" />

        <template #title>{{ isEdit ? `Edit bill of materials v${bom.version_no}` : 'New bill of materials' }}</template>
        <template #subtitle>
            {{ product.code }} — {{ product.name }}.
            <template v-if="isEdit">This version is a draft, so it can still be corrected. Saving returns to the product.</template>
            <template v-else>
                Saved as a draft unless you activate it below; one bill of materials per product is active at a time.
                Saving returns to the product.
            </template>
        </template>

        <FormLayout @submit="submit">
            <!-- What the lines below are a copy of, so nobody wonders where they came from. -->
            <p
                v-if="!isEdit && basedOn"
                class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-ink-700"
                data-based-on
            >
                Starts as a copy of v{{ basedOn.version_no }}, the newest version ({{ STATUS_WORDS[basedOn.status] ?? basedOn.status }}).
                Change what is different and save it as a new version.
                <template v-if="basedOn.status === 'draft' && can('bom.update')">
                    To correct v{{ basedOn.version_no }} itself,
                    <Link :href="`/boms/${basedOn.id}/edit`" class="font-medium text-brand-700 underline">edit that draft</Link>.
                </template>
            </p>

            <Card title="Basis" rule="BR-1">
                <div class="grid gap-x-4 gap-y-3 sm:grid-cols-3">
                    <FormField
                        label="Base quantity (pieces)"
                        hint="Every line quantity below is per this many finished pieces."
                        :error="form.errors.base_qty"
                        required
                    >
                        <TextInput v-model="form.base_qty" type="number" step="0.000001" numeric />
                    </FormField>

                    <FormField label="Against spec" :error="form.errors.product_spec_id">
                        <TextInput
                            :model-value="spec ? `v${spec.version_no} (current)` : 'No current spec'"
                            disabled
                        />
                    </FormField>

                    <FormField label="Notes" :error="form.errors.notes">
                        <TextInput v-model="form.notes" />
                    </FormField>

                    <FormField
                        v-if="canActivate"
                        label="On saving"
                        rule="PD-3"
                        class="sm:col-span-3"
                        :error="form.errors.activate"
                    >
                        <label class="flex items-center gap-2 text-sm text-ink-700">
                            <input v-model="form.activate" type="checkbox" class="form-checkbox">
                            Activate this bill of materials
                        </label>
                        <p class="mt-1 text-[11px] text-ink-500">
                            One BOM per product is active at a time, and a job card cannot be released
                            without one. Ticking this supersedes the version currently active.
                        </p>
                    </FormField>
                </div>
            </Card>

            <Card
                title="Lines"
                rule="BR-9 · BR-10"
                subtitle="What one job draws from the store; the right-hand figure is a 30,000-piece dry run"
                :padded="false"
            >
                <div class="p-3">
                    <LineItemsTable
                        :columns="lineColumns"
                        :lines="form.lines"
                        :errors="form.errors"
                        :can-remove="() => form.lines.length > 1"
                        @add="addLine"
                        @remove="removeLine"
                    >
                        <template #cell:item_id="{ line }">
                            <SelectInput
                                v-model="line.item_id"
                                placeholder="— material —"
                                :options="items"
                                value-key="id"
                                label-key="code"
                                hint-key="name"
                                @update:model-value="onItemChange(line)"
                            />
                        </template>
                        <template #cell:uom_id="{ line }">
                            <SelectInput
                                v-model="line.uom_id"
                                placeholder="—"
                                :options="uoms"
                                value-key="id"
                                label-key="code"
                                hint-key="name"
                            />
                        </template>
                        <template #cell:qty_per_base="{ line }">
                            <TextInput v-model="line.qty_per_base" type="number" step="0.000001" numeric />
                        </template>
                        <template #cell:wastage_pct="{ line }">
                            <TextInput v-model="line.wastage_pct" type="number" step="0.01" numeric />
                        </template>
                        <template #cell:colour_index="{ line }">
                            <SelectInput
                                v-model="line.colour_index"
                                placeholder="— all —"
                                :options="colourOptions"
                            />
                        </template>
                        <template #cell:is_optional="{ line, index }">
                            <input
                                v-model="line.is_optional"
                                type="checkbox"
                                class="form-checkbox mt-2"
                                :aria-label="`Optional, line ${index + 1}`"
                            />
                        </template>
                        <template #cell:scaled="{ line }">
                            <span class="block pt-1.5 text-right tnum text-ink-600">{{ qty(scaled(line)) }}</span>
                        </template>
                    </LineItemsTable>
                </div>
            </Card>

            <p class="text-xs text-ink-500">
                Mark a line <strong>optional</strong> only when a job may genuinely run without it.
                A job card cannot complete while a non-optional item was never issued (I7).
            </p>

            <FormFooter
                :form="form"
                :label="isEdit
                    ? (form.activate ? 'Save and activate' : 'Save draft')
                    : (form.activate ? 'Create and activate' : 'Create draft BOM')"
                :cancel-href="`/products/${product.id}`"
                @save="submit"
            />
        </FormLayout>
    </AppLayout>
</template>
