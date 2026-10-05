<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useLotPicker } from '@/composables/useLotPicker';
import { useConfirmedReset } from '@/composables/useConfirmedReset';
import AppLayout from '@/Layouts/AppLayout.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import { money, qty, todayIso, isoDate } from '@/plugins/formatting';

const props = defineProps({
    transfer: { type: Object, default: null },
    warehouses: { type: Array, default: () => [] },
    lots: { type: Array, default: () => [] },
    /** `{ shown, total, limit }` for the lot list above. */
    lotsMeta: { type: Object, default: null },
});

const isEdit = computed(() => Boolean(props.transfer?.id));

const form = useForm({
    from_warehouse_id: props.transfer?.from_warehouse_id ?? props.warehouses[0]?.id ?? '',
    to_warehouse_id: props.transfer?.to_warehouse_id ?? props.warehouses[1]?.id ?? props.warehouses[0]?.id ?? '',
    transfer_date: isoDate(props.transfer?.transfer_date) || todayIso(),
    remarks: props.transfer?.remarks ?? '',
    lines: (props.transfer?.lines ?? []).map((line) => ({
        lot_id: line.lot_id,
        qty: line.qty,
    })),
});

const warehouseLots = computed(() =>
    props.lots.filter((lot) => Number(lot.warehouse_id) === Number(form.from_warehouse_id)),
);

const lotOptions = computed(() =>
    warehouseLots.value.map((lot) => ({
        ...lot,
        label: lot.lot_no,
        hint: [
            lot.item?.code ?? lot.product?.code,
            `free ${qty(lot.free_qty ?? lot.balance_qty)}`,
            lot.status,
        ].filter(Boolean).join(' · '),
    })),
);

const toWarehouses = computed(() =>
    props.warehouses.filter((warehouse) => Number(warehouse.id) !== Number(form.from_warehouse_id)),
);

const pickLotId = ref('');

/**
 * The scan box. A handheld reader types the label's code and presses Enter, so this is a text
 * field that adds a line on Enter and clears itself for the next label.
 */
const scanCode = ref('');
const scanMessage = ref(null);

async function onScan() {
    const code = scanCode.value.trim();

    if (code === '') return;

    const lot = await scanLot(code);

    if (!lot) {
        scanMessage.value = { tone: 'error', text: `No lot "${code}" in this warehouse.` };

        return;
    }

    const already = form.lines.some((line) => Number(line.lot_id) === Number(lot.id));

    addLine(Number(lot.id));
    scanMessage.value = { tone: 'ok', text: already ? `${lot.lot_no} is already on the list.` : `Added ${lot.lot_no}.` };
    scanCode.value = '';
}

watch(pickLotId, (id) => {
    if (!id) return;
    addLine(Number(id));
    pickLotId.value = '';
});

const { lotOf, known: knownLots, search: lotSearch, loading: lotsLoading, partial: lotsPartial, scan: scanLot } = useLotPicker({
    lots: () => props.lots,
    meta: () => props.lotsMeta,
    warehouse: () => form.from_warehouse_id,
    keep: () => form.lines.map((line) => Number(line.lot_id)),
});

function addLine(lotId) {
    if (form.lines.some((line) => Number(line.lot_id) === Number(lotId))) return;

    const lot = knownLots.get(Number(lotId));
    if (!lot) return;

    form.lines = [...form.lines, { lot_id: lot.id, qty: '' }];
}

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
}

useConfirmedReset(
    () => [form.from_warehouse_id],
    () => form.lines.length > 0,
    ([warehouseId]) => {
        form.lines = form.lines.filter((line) => Number(lotOf(line)?.warehouse_id) === Number(warehouseId));

        if (Number(form.to_warehouse_id) === Number(warehouseId)) {
            form.to_warehouse_id = toWarehouses.value[0]?.id ?? '';
        }
    },
    ([warehouseId]) => {
        form.from_warehouse_id = warehouseId;
    },
    {
        title: 'Change the source warehouse and clear the lines?',
        message: 'A transfer moves lots out of one warehouse. The lots already added are in the previous one and will be removed.',
    },
);

const sameWarehouse = computed(() =>
    form.from_warehouse_id !== '' && Number(form.from_warehouse_id) === Number(form.to_warehouse_id),
);

const invalidQty = computed(() =>
    form.lines.some((line) => {
        const n = Number(line.qty);
        const free = Number(lotOf(line)?.free_qty ?? lotOf(line)?.balance_qty ?? 0);
        return line.qty !== '' && (n <= 0 || n > free + 0.000001);
    }),
);

function submit() {
    const payload = {
        from_warehouse_id: form.from_warehouse_id,
        to_warehouse_id: form.to_warehouse_id,
        transfer_date: form.transfer_date,
        remarks: form.remarks || null,
        lines: form.lines.map((line) => ({
            lot_id: line.lot_id,
            qty: line.qty,
        })),
    };

    if (isEdit.value) {
        form.transform(() => payload).put(`/stock-transfers/${props.transfer.id}`);
        return;
    }

    form.transform(() => payload).post('/stock-transfers');
}
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit transfer' : 'New transfer'" />

        <template #title>{{ isEdit ? 'Edit transfer' : 'New transfer' }}</template>
        <template #subtitle>Existing lots only. Dispatch writes stock into transit; this form does not.</template>

        <FormLayout @submit="submit">
            <Card title="Transfer details">
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField label="From warehouse" :error="form.errors.from_warehouse_id" required>
                        <SelectInput
                            v-model="form.from_warehouse_id"
                            :placeholder="null"
                            :options="warehouses"
                            value-key="id"
                            label-key="name"
                        />
                    </FormField>
                    <FormField label="To warehouse" :error="form.errors.to_warehouse_id" required>
                        <SelectInput
                            v-model="form.to_warehouse_id"
                            :placeholder="null"
                            :options="toWarehouses"
                            value-key="id"
                            label-key="name"
                        />
                    </FormField>
                    <FormField label="Transfer date" :error="form.errors.transfer_date" required>
                        <DateInput v-model="form.transfer_date" />
                    </FormField>
                    <FormField label="Remarks" :error="form.errors.remarks">
                        <textarea v-model="form.remarks" rows="2" class="form-textarea" maxlength="255" />
                    </FormField>
                </div>
            </Card>

            <Card title="Lines" subtitle="Each line is a quantity taken from one existing lot in the source warehouse" :padded="false">
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

                    <div class="mt-3">
                        <FormField
                            label="Or scan a lot label"
                            hint="Point the scanner at the label's barcode, or type the lot number and press Enter."
                            :error="scanMessage?.tone === 'error' ? scanMessage.text : null"
                        >
                            <TextInput
                                v-model="scanCode"
                                placeholder="Scan here"
                                autocomplete="off"
                                @keydown.enter.prevent="onScan"
                            />
                        </FormField>
                        <p v-if="scanMessage?.tone === 'ok'" role="status" class="mt-1 text-xs text-emerald-800">{{ scanMessage.text }}</p>
                    </div>

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

                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-ink-700">
                        <tr>
                            <th class="px-3 py-2 text-left">Lot</th>
                            <th class="px-3 py-2 text-right">On hand</th>
                            <th class="px-3 py-2 text-right">Free</th>
                            <th class="px-3 py-2 text-right">Unit cost</th>
                            <th class="px-3 py-2 text-right">Qty</th>
                            <th class="w-10 px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="form.lines.length === 0">
                            <td colspan="6" class="px-3 py-8 text-center text-sm text-ink-500">
                                Pick a lot above. A source lot may appear only once.
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
                            <td class="px-3 py-2 text-right tnum">{{ qty(lotOf(line)?.free_qty) }}</td>
                            <td class="px-3 py-2 text-right tnum">{{ money(lotOf(line)?.unit_cost) }}</td>
                            <td class="px-3 py-2">
                                <TextInput
                                    v-model="line.qty"
                                    type="number"
                                    step="0.000001"
                                    min="0"
                                    numeric
                                    :error="form.errors[`lines.${index}.qty`] || form.errors[`lines.${index}.lot_id`]"
                                />
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
                <Card title="How it moves" rule="IN-4">
                    <p class="text-xs leading-relaxed text-ink-500">
                        Saving a draft writes no stock. Dispatch posts the quantity into the transit warehouse.
                        Receive posts it into the destination as a new child lot.
                    </p>
                    <p v-if="sameWarehouse" class="mt-3 rounded bg-rose-50 px-2 py-1.5 text-xs text-rose-800">
                        Source and destination must be different warehouses.
                    </p>
                    <p v-if="invalidQty" class="mt-2 rounded bg-rose-50 px-2 py-1.5 text-xs text-rose-800">
                        Quantity must be greater than zero and within the lot’s free quantity.
                    </p>
                </Card>
            </template>

            <template #footer>
                <FormFooter
                    :form="form"
                    :disabled="form.lines.length === 0 || sameWarehouse || invalidQty"
                    :disabled-reason="form.lines.length === 0
                        ? 'Add at least one lot to transfer.'
                        : sameWarehouse
                            ? 'The source and destination warehouse are the same.'
                            : invalidQty ? 'A line has no quantity, or more than the lot has free.' : null"
                    cancel-href="/stock-transfers"
                    :label="isEdit ? 'Save draft' : 'Save draft'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
