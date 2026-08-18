<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import { money, qty } from '@/plugins/formatting';

const props = defineProps({
    adjustment: { type: Object, default: null },
    warehouses: { type: Array, default: () => [] },
    lots: { type: Array, default: () => [] },
    band: { type: [Number, String], default: 25000 },
});

const isEdit = computed(() => Boolean(props.adjustment?.id));

const form = useForm({
    warehouse_id: props.adjustment?.warehouse_id ?? props.warehouses[0]?.id ?? '',
    reason: props.adjustment?.reason ?? '',
    lines: (props.adjustment?.lines ?? []).map((line) => ({
        lot_id: line.lot_id,
        qty_delta: line.qty_delta,
        remarks: line.remarks ?? '',
    })),
});

const warehouseLots = computed(() =>
    props.lots.filter((lot) => Number(lot.warehouse_id) === Number(form.warehouse_id)),
);

const lotOptions = computed(() =>
    warehouseLots.value.map((lot) => ({
        ...lot,
        label: lot.lot_no,
        hint: [
            lot.item?.code ?? lot.product?.code,
            `on hand ${qty(lot.balance_qty)}`,
            lot.status,
        ].filter(Boolean).join(' · '),
    })),
);

const pickLotId = ref('');

watch(pickLotId, (id) => {
    if (!id) return;
    addLine(Number(id));
    pickLotId.value = '';
});

function lotOf(line) {
    return props.lots.find((lot) => Number(lot.id) === Number(line.lot_id)) ?? null;
}

function addLine(lotId) {
    if (form.lines.some((line) => Number(line.lot_id) === Number(lotId))) return;

    const lot = props.lots.find((row) => Number(row.id) === Number(lotId));
    if (!lot) return;

    form.lines = [...form.lines, { lot_id: lot.id, qty_delta: '', remarks: '' }];
}

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
}

watch(() => form.warehouse_id, (warehouseId) => {
    form.lines = form.lines.filter((line) => Number(lotOf(line)?.warehouse_id) === Number(warehouseId));
});

const totalValue = computed(() =>
    form.lines.reduce((sum, line) => {
        const lot = lotOf(line);
        return sum + Math.abs(Number(line.qty_delta) || 0) * (Number(lot?.unit_cost) || 0);
    }, 0),
);

const aboveBand = computed(() => totalValue.value > Number(props.band));

const zeroLine = computed(() =>
    form.lines.some((line) => line.qty_delta !== '' && Math.abs(Number(line.qty_delta)) < 0.000001),
);

function direction(qtyDelta) {
    const n = Number(qtyDelta);
    if (!n) return '';
    return n > 0 ? 'In' : 'Out';
}

function submit() {
    const payload = {
        warehouse_id: form.warehouse_id,
        reason: form.reason,
        lines: form.lines.map((line) => ({
            lot_id: line.lot_id,
            qty_delta: line.qty_delta,
            remarks: line.remarks || null,
        })),
    };

    if (isEdit.value) {
        form.transform(() => payload).put(`/stock-adjustments/${props.adjustment.id}`);
        return;
    }

    form.transform(() => payload).post('/stock-adjustments');
}
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit adjustment' : 'New adjustment'" />

        <template #title>{{ isEdit ? 'Edit adjustment' : 'New adjustment' }}</template>
        <template #subtitle>Existing lots only. Positive quantity is an adjustment in; negative is an adjustment out.</template>

        <FormLayout @submit="submit">
            <Card title="Header">
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField label="Warehouse" :error="form.errors.warehouse_id" required>
                        <SelectInput
                            v-model="form.warehouse_id"
                            :placeholder="null"
                            :options="warehouses"
                            value-key="id"
                            label-key="name"
                        />
                    </FormField>
                    <FormField label="Reason" :error="form.errors.reason" required hint="Free text — why this correction exists.">
                        <textarea v-model="form.reason" rows="2" class="form-textarea" maxlength="500" />
                    </FormField>
                </div>
            </Card>

            <Card title="Lines" subtitle="Each line is a signed quantity against one existing lot in this warehouse" :padded="false">
                <div class="border-b border-slate-200 px-3 py-3">
                    <FormField label="Add an existing lot" :error="form.errors.lines">
                        <SelectInput
                            v-model="pickLotId"
                            placeholder="Search lot number…"
                            :options="lotOptions"
                            value-key="id"
                            label-key="label"
                            hint-key="hint"
                        />
                    </FormField>
                </div>

                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-ink-700">
                        <tr>
                            <th class="px-3 py-2 text-left">Lot</th>
                            <th class="px-3 py-2 text-left">On hand</th>
                            <th class="px-3 py-2 text-right">Unit cost</th>
                            <th class="px-3 py-2 text-right">Qty (+ in / − out)</th>
                            <th class="px-3 py-2 text-left">Direction</th>
                            <th class="px-3 py-2 text-left">Remarks</th>
                            <th class="w-10 px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="form.lines.length === 0">
                            <td colspan="7" class="px-3 py-8 text-center text-sm text-ink-500">
                                Pick a lot above. Zero quantity is not an adjustment.
                            </td>
                        </tr>
                        <tr v-for="(line, index) in form.lines" :key="`${line.lot_id}-${index}`">
                            <td class="px-3 py-2">
                                <div class="font-mono text-xs">{{ lotOf(line)?.lot_no }}</div>
                                <div class="text-xs text-ink-500">
                                    {{ lotOf(line)?.item?.code ?? lotOf(line)?.product?.code ?? '—' }}
                                </div>
                                <Badge v-if="lotOf(line)?.status && lotOf(line).status !== 'available'" :status="lotOf(line).status" class="mt-1" />
                            </td>
                            <td class="px-3 py-2 text-right tnum">{{ qty(lotOf(line)?.balance_qty) }}</td>
                            <td class="px-3 py-2 text-right tnum">{{ money(lotOf(line)?.unit_cost) }}</td>
                            <td class="px-3 py-2">
                                <TextInput
                                    v-model="line.qty_delta"
                                    type="number"
                                    step="0.000001"
                                    numeric
                                    :error="form.errors[`lines.${index}.qty_delta`] || form.errors[`lines.${index}.lot_id`]"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <Badge
                                    v-if="direction(line.qty_delta)"
                                    :tone="Number(line.qty_delta) > 0 ? 'success' : 'warning'"
                                    :label="direction(line.qty_delta)"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <TextInput v-model="line.remarks" />
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    class="rounded p-1 text-ink-400 transition hover:bg-rose-50 hover:text-rose-600"
                                    aria-label="Remove line"
                                    @click="removeLine(index)"
                                >
                                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path d="M6 6l8 8M14 6l-8 8" stroke-linecap="round" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>

            <template #rail>
                <Card title="Approval value" rule="06-rbac §5">
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Σ |qty| × lot cost</dt>
                            <dd class="text-base font-semibold tnum text-ink-900">{{ money(totalValue) }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-2.5">
                            <dt class="text-xs text-ink-500">Store manager band</dt>
                            <dd class="tnum text-ink-700">{{ money(band) }}</dd>
                        </div>
                    </dl>
                    <p v-if="aboveBand" class="mt-3 rounded bg-amber-50 px-2 py-1.5 text-[11px] leading-relaxed text-amber-900">
                        Above the store manager band — posting will need the Managing Director.
                    </p>
                    <p v-else class="mt-3 text-[11px] leading-relaxed text-ink-500">
                        Drafting writes no stock. A store manager may post within the band; above it, only the MD.
                    </p>
                    <p v-if="zeroLine" class="mt-2 rounded bg-rose-50 px-2 py-1.5 text-[11px] text-rose-800">
                        A line of zero is not an adjustment.
                    </p>
                </Card>
            </template>

            <template #footer>
                <FormFooter
                    :form="form"
                    :disabled="form.lines.length === 0 || !form.reason || zeroLine"
                    cancel-href="/stock-adjustments"
                    :label="isEdit ? 'Save draft' : 'Save draft'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
