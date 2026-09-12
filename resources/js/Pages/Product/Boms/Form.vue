<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { qty } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';

const props = defineProps({
    product: { type: Object, required: true },
    spec: { type: Object, default: null },
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
    base_qty: 1000,
    notes: '',
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

function submit() {
    form.post(`/products/${props.product.id}/boms`);
}
</script>

<template>
    <AppLayout>
        <Head :title="`New BOM — ${product.code}`" />

        <template #title>New bill of materials</template>
        <template #subtitle>
            {{ product.code }} — {{ product.name }}. Saved as a draft unless you activate it below;
            one BOM per product is active at a time (PD-3). Saving returns to the product.
        </template>

        <FormLayout @submit="submit">
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
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-ink-700">
                        <tr>
                            <th class="px-3 py-1.5 text-left">Item</th>
                            <th class="px-3 py-1.5 text-left" style="width: 8rem">UoM</th>
                            <th class="px-3 py-1.5 text-right" style="width: 10rem">Qty / base</th>
                            <th class="px-3 py-1.5 text-right" style="width: 8rem">Wastage %</th>
                            <th class="px-3 py-1.5 text-left" style="width: 10rem">Colour</th>
                            <th class="px-3 py-1.5 text-center" style="width: 6rem">Optional</th>
                            <th class="px-3 py-1.5 text-right" style="width: 9rem">For 30,000</th>
                            <th class="px-3 py-1.5" style="width: 3rem" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(line, index) in form.lines" :key="index">
                            <td class="px-3 py-1.5">
                                <SelectInput
                                    v-model="line.item_id"
                                    placeholder="— item —"
                                    :options="items"
                                    value-key="id"
                                    label-key="code"
                                    hint-key="name"
                                    @update:model-value="onItemChange(line)"
                                />
                            </td>
                            <td class="px-3 py-1.5">
                                <SelectInput
                                    v-model="line.uom_id"
                                    placeholder="—"
                                    :options="uoms"
                                    value-key="id"
                                    label-key="code"
                                />
                            </td>
                            <td class="px-3 py-1.5">
                                <TextInput v-model="line.qty_per_base" type="number" step="0.000001" numeric />
                            </td>
                            <td class="px-3 py-1.5">
                                <TextInput v-model="line.wastage_pct" type="number" step="0.01" numeric />
                            </td>
                            <td class="px-3 py-1.5">
                                <SelectInput
                                    v-model="line.colour_index"
                                    placeholder="— all —"
                                    :options="colourOptions"
                                />
                            </td>
                            <td class="px-3 py-1.5 text-center">
                                <input v-model="line.is_optional" type="checkbox" class="form-checkbox" />
                            </td>
                            <td class="px-3 py-1.5 text-right tnum text-ink-600">{{ qty(scaled(line)) }}</td>
                            <td class="px-3 py-1.5 text-right">
                                <button
                                    v-if="form.lines.length > 1"
                                    type="button"
                                    class="text-ink-400 hover:text-rose-600"
                                    :aria-label="`Remove line ${index + 1}`"
                                    @click="removeLine(index)"
                                >
                                    ×
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="border-t border-slate-200 px-3 py-2">
                    <Button size="sm" @click="addLine">+ Add line</Button>
                </div>

                <p v-if="form.errors.lines" class="border-t border-slate-200 px-3 py-2 text-xs text-rose-600">
                    {{ form.errors.lines }}
                </p>
            </Card>

            <p class="text-xs text-ink-500">
                Mark a line <strong>optional</strong> only when a job may genuinely run without it.
                A job card cannot complete while a non-optional item was never issued (I7).
            </p>

            <FormFooter
                :form="form"
                :label="form.activate ? 'Create and activate' : 'Create draft BOM'"
                :cancel-href="`/products/${product.id}`"
                @save="submit"
            />
        </FormLayout>
    </AppLayout>
</template>
