<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useLotPicker } from '@/composables/useLotPicker';
import { useConfirmedReset } from '@/composables/useConfirmedReset';
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
    /** `{ shown, total, limit }` for the lot list above. */
    lotsMeta: { type: Object, default: null },
    band: { type: [Number, String], default: 25000 },
});

const isEdit = computed(() => Boolean(props.adjustment?.id));

const form = useForm({
    warehouse_id: props.adjustment?.warehouse_id ?? props.warehouses[0]?.id ?? '',
    reason: props.adjustment?.reason ?? '',
    lines: (props.adjustment?.lines ?? []).map((line) => ({
        lot_id: line.lot_id,
        qty_delta: line.qty_delta,
        // How the signed figure is entered: a direction and a plain quantity.
        direction: Number(line.qty_delta) < 0 ? 'out' : 'in',
        amount: line.qty_delta === null || line.qty_delta === '' ? '' : Math.abs(Number(line.qty_delta)),
        remarks: line.remarks ?? '',
    })),
});

/**
 * The signed quantity the server stores, from the two things a person actually decides.
 *
 * It used to be one box labelled "Qty (+ in / − out)". A forgotten minus sign added stock that
 * should have been written off, and nothing on the row showed what the lot would hold after.
 */
function setDelta(line) {
    const amount = Number(line.amount);

    line.qty_delta = line.amount === '' || Number.isNaN(amount) ? '' : (line.direction === 'out' ? -amount : amount);
}

function balanceAfter(line) {
    const lot = lotOf(line);

    if (!lot || line.qty_delta === '') return null;

    return Number(lot.balance_qty) + Number(line.qty_delta);
}

/** Taking out more than the lot holds — refused by the server; said here first. */
const overdrawn = computed(() => form.lines.some((line) => (balanceAfter(line) ?? 0) < -0.000001));

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

const { lotOf, known: knownLots, search: lotSearch, loading: lotsLoading, partial: lotsPartial } = useLotPicker({
    lots: () => props.lots,
    meta: () => props.lotsMeta,
    warehouse: () => form.warehouse_id,
    keep: () => form.lines.map((line) => Number(line.lot_id)),
});

function addLine(lotId) {
    if (form.lines.some((line) => Number(line.lot_id) === Number(lotId))) return;

    const lot = knownLots.get(Number(lotId));
    if (!lot) return;

    form.lines = [...form.lines, { lot_id: lot.id, qty_delta: '', direction: 'out', amount: '', remarks: '' }];
}

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
}

useConfirmedReset(
    () => [form.warehouse_id],
    () => form.lines.length > 0,
    ([warehouseId]) => {
        form.lines = form.lines.filter((line) => Number(lotOf(line)?.warehouse_id) === Number(warehouseId));
    },
    ([warehouseId]) => {
        form.warehouse_id = warehouseId;
    },
    {
        title: 'Change the warehouse and clear the lines?',
        message: 'An adjustment is made in one warehouse. The lots already added are in the previous one and will be removed.',
    },
);

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

            <Card title="Lines" subtitle="Each line takes stock out of, or puts stock into, one existing lot in this warehouse" :padded="false">
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

                    <!--
                        The list above holds this warehouse's lots, up to a limit. When there are
                        more, this asks the server for the ones that match — a lot past the
                        limit used to be simply missing, with nothing to say so.
                    -->
                    <div v-if="lotsPartial || lotSearch" class="mt-2 flex flex-wrap items-end gap-3">
                        <FormField label="Find a lot not in the list" class="min-w-0 flex-1 basis-64">
                            <TextInput v-model="lotSearch" placeholder="Part of a lot number, material code or name" />
                        </FormField>
                        <p class="pb-2 text-xs text-ink-600" role="status">
                            <template v-if="lotsLoading">Searching…</template>
                            <template v-else-if="lotsPartial">
                                Showing {{ lotsPartial.shown }} of {{ lotsPartial.total }} lots in this warehouse.
                            </template>
                            <template v-else>{{ lotsMeta?.total ?? 0 }} found.</template>
                        </p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-ink-700">
                        <tr>
                            <th class="px-3 py-2 text-left">Lot</th>
                            <th class="px-3 py-2 text-right">On hand now</th>
                            <th class="px-3 py-2 text-right">Unit cost</th>
                            <th class="px-3 py-2 text-left">Stock goes</th>
                            <th class="px-3 py-2 text-right">Quantity</th>
                            <th class="px-3 py-2 text-right">On hand after</th>
                            <th class="px-3 py-2 text-left">Remarks</th>
                            <th class="w-10 px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="form.lines.length === 0">
                            <td colspan="8" class="px-3 py-8 text-center text-sm text-ink-500">
                                Pick a lot above, then say whether stock is going out or coming in and how much.
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
                                <div class="inline-flex rounded-md border border-slate-300" role="group" :aria-label="`Direction, line ${index + 1}`">
                                    <button
                                        type="button"
                                        class="min-h-9 rounded-l-md px-3 text-sm transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                        :class="line.direction === 'out' ? 'bg-amber-100 font-medium text-amber-900' : 'bg-white text-ink-700 hover:bg-slate-50'"
                                        :aria-pressed="line.direction === 'out'"
                                        @click="line.direction = 'out'; setDelta(line)"
                                    >Out</button>
                                    <button
                                        type="button"
                                        class="min-h-9 rounded-r-md border-l border-slate-300 px-3 text-sm transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                        :class="line.direction === 'in' ? 'bg-emerald-100 font-medium text-emerald-900' : 'bg-white text-ink-700 hover:bg-slate-50'"
                                        :aria-pressed="line.direction === 'in'"
                                        @click="line.direction = 'in'; setDelta(line)"
                                    >In</button>
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <TextInput
                                    v-model="line.amount"
                                    type="number"
                                    step="0.000001"
                                    min="0"
                                    numeric
                                    :aria-label="`Quantity, line ${index + 1}`"
                                    :error="form.errors[`lines.${index}.qty_delta`] || form.errors[`lines.${index}.lot_id`]"
                                    @update:model-value="setDelta(line)"
                                />
                            </td>
                            <td class="px-3 py-2 text-right tnum">
                                <span v-if="balanceAfter(line) === null" class="text-ink-500">—</span>
                                <span v-else :class="balanceAfter(line) < 0 ? 'font-medium text-rose-700' : 'text-ink-900'">
                                    {{ qty(balanceAfter(line)) }}
                                </span>
                                <p v-if="(balanceAfter(line) ?? 0) < 0" class="text-xs text-rose-700">more than the lot holds</p>
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
                </div>
            </Card>

            <template #rail>
                <Card title="Approval value" rule="06-rbac §5">
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Value being adjusted</dt>
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
                    :disabled="form.lines.length === 0 || !form.reason || zeroLine || overdrawn"
                    :disabled-reason="form.lines.length === 0
                        ? 'Add at least one lot to adjust.'
                        : !form.reason
                            ? 'Give the reason for the adjustment.'
                            : zeroLine
                                ? 'A line has a quantity of zero. Enter the change or remove the line.'
                                : overdrawn ? 'A line takes out more than its lot holds.' : null"
                    cancel-href="/stock-adjustments"
                    :label="isEdit ? 'Save draft' : 'Save draft'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
