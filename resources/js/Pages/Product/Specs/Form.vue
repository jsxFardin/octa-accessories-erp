<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { mm, qty, titleCase } from '@/plugins/formatting';

const props = defineProps({
    product: { type: Object, required: true },
    current: { type: Object, default: null },
    cutTypes: { type: Array, default: () => [] },
    foldTypes: { type: Array, default: () => [] },
    schemes: { type: Array, default: () => [] },
});

/**
 * P3 — a spec is immutable once anything references it, so this screen only ever creates a
 * version. It opens on the current one because most revisions move a single dimension.
 */
const form = useForm({
    label_width_mm: props.current?.label_width_mm ?? '',
    label_height_mm: props.current?.label_height_mm ?? '',
    web_width_mm: props.current?.web_width_mm ?? '',
    selvedge_mm: props.current?.selvedge_mm ?? 5,
    lane_gap_mm: props.current?.lane_gap_mm ?? 2,
    cut_gap_mm: props.current?.cut_gap_mm ?? 2,
    ends: props.current?.ends ?? '',
    base_material: props.current?.base_material ?? '',
    fabric_gsm: props.current?.fabric_gsm ?? '',
    warp_ratio: props.current?.warp_ratio ?? 0.6,
    colours: props.current?.colours ?? 1,
    colour_list: props.current?.colour_list?.length
        ? props.current.colour_list.map((colour) => ({ ...colour }))
        : [{ index: 1, name: '', pantone: '', weight_pct: 100 }],
    cut_type: props.current?.cut_type ?? '',
    fold_type: props.current?.fold_type ?? '',
    finish: props.current?.finish ?? '',
    coverage_pct: props.current?.coverage_pct ?? 35,
    bundle_size: props.current?.bundle_size ?? 500,
    bundles_per_carton: props.current?.bundles_per_carton ?? 20,
    fibre_composition: props.current?.fibre_composition ?? '',
    country_of_origin: props.current?.country_of_origin ?? 'Bangladesh',
    care_symbols: props.current?.care_symbols ?? [],
    claims: props.current?.claims ?? [],
    notes: '',
});

const trialQty = ref(10000);
const derived = ref(null);
const geometryError = ref(null);

/**
 * BR-4/5/6 — pitch, labels per metre and ends are computed, never typed. The same calculator
 * answers here and at costing, so what the designer sees is what the quote will use.
 */
async function refreshDerived() {
    if (!form.label_width_mm || !form.label_height_mm) {
        derived.value = null;

        return;
    }

    const response = await fetch('/specs/preview', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({
            product_type: props.product.product_type,
            cut_type: form.cut_type || null,
            label_width_mm: Number(form.label_width_mm),
            label_height_mm: Number(form.label_height_mm),
            web_width_mm: Number(form.web_width_mm) || null,
            selvedge_mm: Number(form.selvedge_mm) || 0,
            lane_gap_mm: Number(form.lane_gap_mm) || 0,
            cut_gap_mm: Number(form.cut_gap_mm) || 0,
            ends: Number(form.ends) || null,
            fabric_gsm: Number(form.fabric_gsm) || null,
            warp_ratio: Number(form.warp_ratio) || null,
            colours: Number(form.colours) || 1,
            coverage_pct: Number(form.coverage_pct) || 0,
            bundle_size: Number(form.bundle_size) || null,
            bundles_per_carton: Number(form.bundles_per_carton) || null,
            trial_qty: Number(trialQty.value) || null,
        }),
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        geometryError.value = Object.values(payload.errors ?? {}).flat()[0] ?? 'This geometry cannot produce a label.';
        derived.value = null;

        return;
    }

    geometryError.value = null;
    derived.value = await response.json();
}

watch(
    () => [
        form.label_width_mm, form.label_height_mm, form.web_width_mm, form.selvedge_mm,
        form.lane_gap_mm, form.cut_gap_mm, form.ends, form.cut_type, form.fabric_gsm,
        form.coverage_pct, trialQty.value,
    ].join('|'),
    refreshDerived,
    { immediate: true },
);

/** The colour table follows the colour count, because BR-9 weights consumption by it. */
watch(() => Number(form.colours), (count) => {
    const rows = [...form.colour_list].slice(0, count);

    while (rows.length < count) {
        rows.push({ index: rows.length + 1, name: '', pantone: '', weight_pct: 0 });
    }

    form.colour_list = rows.map((row, index) => ({ ...row, index: index + 1 }));
});

const weightTotal = computed(() =>
    form.colour_list.reduce((sum, colour) => sum + (Number(colour.weight_pct) || 0), 0),
);

function toggleClaim(scheme) {
    form.claims = form.claims.includes(scheme)
        ? form.claims.filter((claim) => claim !== scheme)
        : [...form.claims, scheme];
}

function submit() {
    form.post(`/products/${props.product.id}/specs`);
}
</script>

<template>
    <AppLayout>
        <Head :title="`New spec — ${product.code}`" />

        <template #title>New specification</template>
        <template #subtitle>
            {{ product.code }} — {{ product.name }}.
            {{ current ? `Starting from v${current.version_no}; saving creates the next version as a draft.` : 'The first version, saved as a draft.' }}
        </template>

        <FormLayout @submit="submit">
            <div class="grid gap-4 xl:grid-cols-3">
                <div class="space-y-4 xl:col-span-2">
                    <Card title="Geometry" rule="BR-4 · BR-5" subtitle="Everything the pitch and the ends are computed from">
                        <div class="grid gap-x-4 gap-y-3 sm:grid-cols-3">
                            <FormField label="Label width (mm)" :error="form.errors.label_width_mm" required>
                                <TextInput v-model="form.label_width_mm" type="number" step="0.01" numeric />
                            </FormField>

                            <FormField label="Label height (mm)" :error="form.errors.label_height_mm" required>
                                <TextInput v-model="form.label_height_mm" type="number" step="0.01" numeric />
                            </FormField>

                            <FormField label="Web width (mm)" hint="Blank for a product that is not woven on a web." :error="form.errors.web_width_mm">
                                <TextInput v-model="form.web_width_mm" type="number" step="0.01" numeric />
                            </FormField>

                            <FormField label="Selvedge (mm)" :error="form.errors.selvedge_mm">
                                <TextInput v-model="form.selvedge_mm" type="number" step="0.01" numeric />
                            </FormField>

                            <FormField label="Lane gap (mm)" :error="form.errors.lane_gap_mm">
                                <TextInput v-model="form.lane_gap_mm" type="number" step="0.01" numeric />
                            </FormField>

                            <FormField
                                label="Cut gap (mm)"
                                rule="BR-4"
                                hint="Defaults follow the cut type in Configuration → Settings."
                                :error="form.errors.cut_gap_mm"
                            >
                                <TextInput v-model="form.cut_gap_mm" type="number" step="0.01" numeric />
                            </FormField>

                            <FormField label="Cut type" :error="form.errors.cut_type">
                                <SelectInput v-model="form.cut_type" placeholder="— select —" :options="cutTypes" />
                            </FormField>

                            <FormField label="Fold type" :error="form.errors.fold_type">
                                <SelectInput
                                    v-model="form.fold_type"
                                    placeholder="— select —"
                                    :options="foldTypes.map((fold) => ({ value: fold, label: titleCase(fold) }))"
                                />
                            </FormField>

                            <FormField
                                label="Ends"
                                rule="BR-5"
                                hint="Leave blank to accept the suggestion."
                                :error="form.errors.ends"
                            >
                                <TextInput v-model="form.ends" type="number" numeric min="1" />
                            </FormField>
                        </div>
                    </Card>

                    <Card title="Material" rule="BR-9">
                        <div class="grid gap-x-4 gap-y-3 sm:grid-cols-3">
                            <FormField label="Base material" :error="form.errors.base_material">
                                <TextInput v-model="form.base_material" placeholder="Satin" />
                            </FormField>

                            <FormField label="Fabric GSM" hint="Grams per square metre; yarn weight comes from it." :error="form.errors.fabric_gsm">
                                <TextInput v-model="form.fabric_gsm" type="number" step="0.001" numeric />
                            </FormField>

                            <FormField label="Warp ratio" hint="Between 0 and 1." :error="form.errors.warp_ratio">
                                <TextInput v-model="form.warp_ratio" type="number" step="0.0001" numeric />
                            </FormField>

                            <FormField label="Finish" :error="form.errors.finish">
                                <TextInput v-model="form.finish" placeholder="Standard" />
                            </FormField>

                            <FormField label="Coverage %" rule="BR-10" :error="form.errors.coverage_pct">
                                <TextInput v-model="form.coverage_pct" type="number" step="0.01" numeric />
                            </FormField>

                            <FormField label="Colours" :error="form.errors.colours" required>
                                <TextInput v-model="form.colours" type="number" numeric min="1" />
                            </FormField>
                        </div>
                    </Card>

                    <Card
                        title="Colours"
                        rule="BR-9"
                        subtitle="Weights split the yarn between colours; they should add to 100%"
                        :padded="false"
                    >
                        <table class="min-w-full text-sm">
                            <thead class="text-xs text-ink-700">
                                <tr>
                                    <th class="px-3 py-1.5 text-left">#</th>
                                    <th class="px-3 py-1.5 text-left">Name</th>
                                    <th class="px-3 py-1.5 text-left">Pantone</th>
                                    <th class="px-3 py-1.5 text-right">Weight %</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(colour, index) in form.colour_list" :key="index">
                                    <td class="px-3 py-1.5 tnum text-ink-500">{{ colour.index }}</td>
                                    <td class="px-3 py-1.5"><TextInput v-model="colour.name" placeholder="Optical white" /></td>
                                    <td class="px-3 py-1.5"><TextInput v-model="colour.pantone" placeholder="11-0601" /></td>
                                    <td class="px-3 py-1.5"><TextInput v-model="colour.weight_pct" type="number" step="0.01" numeric /></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-slate-200">
                                    <td colspan="3" class="px-3 py-1.5 text-right text-xs text-ink-500">Total</td>
                                    <td
                                        class="px-3 py-1.5 text-right tnum font-medium"
                                        :class="Math.abs(weightTotal - 100) > 0.01 ? 'text-amber-700' : 'text-ink-900'"
                                    >
                                        {{ weightTotal }}%
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </Card>

                    <Card title="Packing and declaration" rule="BR-12">
                        <div class="grid gap-x-4 gap-y-3 sm:grid-cols-2">
                            <FormField label="Bundle size" :error="form.errors.bundle_size">
                                <TextInput v-model="form.bundle_size" type="number" numeric min="1" />
                            </FormField>

                            <FormField label="Bundles per carton" :error="form.errors.bundles_per_carton">
                                <TextInput v-model="form.bundles_per_carton" type="number" numeric min="1" />
                            </FormField>

                            <FormField label="Fibre composition" :error="form.errors.fibre_composition">
                                <TextInput v-model="form.fibre_composition" placeholder="100% Polyester" />
                            </FormField>

                            <FormField label="Country of origin" :error="form.errors.country_of_origin">
                                <TextInput v-model="form.country_of_origin" />
                            </FormField>

                            <FormField
                                label="Claims"
                                rule="Gate 2"
                                hint="What this product may be sold as. The claim it ships with is diluted from what the job actually consumed."
                                class="sm:col-span-2"
                            >
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="scheme in schemes"
                                        :key="scheme"
                                        type="button"
                                        class="rounded-md border px-2 py-1 text-xs"
                                        :class="form.claims.includes(scheme)
                                            ? 'border-brand-500 bg-brand-50 text-brand-800'
                                            : 'border-slate-200 text-ink-600 hover:bg-slate-50'"
                                        @click="toggleClaim(scheme)"
                                    >
                                        {{ scheme }}
                                    </button>
                                </div>
                            </FormField>

                            <FormField label="Notes" :error="form.errors.notes" class="sm:col-span-2">
                                <textarea v-model="form.notes" rows="2" class="form-textarea" />
                            </FormField>
                        </div>
                    </Card>
                </div>

                <!-- Derived, never typed: the same calculator the cost sheet and the floor use. -->
                <Card class="h-fit xl:sticky xl:top-4" title="Derived" rule="BR-4 · BR-5 · BR-6">
                    <p v-if="geometryError" class="rounded-md bg-rose-50 px-3 py-2 text-xs text-rose-700">
                        {{ geometryError }}
                    </p>

                    <dl v-else-if="derived" class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-ink-500">Pitch</dt>
                            <dd class="tnum font-medium">{{ mm(derived.geometry.pitch_mm) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-ink-500">Labels / metre</dt>
                            <dd class="tnum font-medium">{{ derived.geometry.labels_per_metre }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-ink-500">Suggested ends</dt>
                            <dd class="tnum font-medium">{{ derived.geometry.suggested_ends }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-ink-500">Effective cut gap</dt>
                            <dd class="tnum font-medium">{{ mm(derived.geometry.effective_cut_gap_mm) }}</dd>
                        </div>

                        <div class="border-t border-slate-200 pt-2">
                            <FormField label="Trial quantity" hint="A dry run of the consumption plan.">
                                <TextInput v-model="trialQty" type="number" numeric min="1" />
                            </FormField>
                        </div>

                        <template v-if="derived.plan">
                            <div class="flex justify-between">
                                <dt class="text-ink-500">Net metres</dt>
                                <dd class="tnum font-medium">{{ qty(derived.plan.netMetres) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-ink-500">Gross metres</dt>
                                <dd class="tnum font-medium">{{ qty(derived.plan.grossMetres) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-ink-500">Yarn (kg)</dt>
                                <dd class="tnum font-medium">{{ qty(derived.plan.yarnKg) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-ink-500">Cartons</dt>
                                <dd class="tnum font-medium">{{ derived.plan.cartons }}</dd>
                            </div>
                        </template>
                    </dl>

                    <p v-else class="text-xs text-ink-500">Enter a width and a height to see the geometry.</p>
                </Card>
            </div>

            <FormFooter :form="form" label="Create draft spec" :cancel-href="`/products/${product.id}`" @save="submit" />
        </FormLayout>
    </AppLayout>
</template>
