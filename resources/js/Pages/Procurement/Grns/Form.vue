<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import LineItemsTable from '@/Components/Ui/LineItemsTable.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import { money, qty, todayIso } from '@/plugins/formatting';

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    uoms: { type: Array, default: () => [] },
    purchaseOrders: { type: Array, default: () => [] },
    schemes: { type: Array, default: () => [] },
});

function blankLine() {
    return {
        item_id: '',
        uom_id: '',
        qty: '',
        rate: '',
        shade_code: '',
        supplier_batch_no: '',
        roll_length_m: '',
        expiry_date: '',
        cert_scheme: '',
        cert_claim_pct: '',
        cert_document_no: '',
    };
}

const form = useForm({
    supplier_id: '',
    po_id: '',
    warehouse_id: '',
    received_on: todayIso(),
    invoice_no: '',
    challan_no: '',
    freight_amount: 0,
    duty_amount: 0,
    clearing_amount: 0,
    lines: [blankLine()],
});

/** A PO narrows nothing structurally, but a receipt against the wrong supplier is a mess. */
const availableOrders = computed(() =>
    form.supplier_id
        ? props.purchaseOrders.filter((po) => po.supplier_id === Number(form.supplier_id))
        : props.purchaseOrders,
);

function itemFor(line) {
    return props.items.find((item) => item.id === Number(line.item_id));
}

/** Default the UoM and rate from the item master the moment one is chosen. */
function onItemChange(line) {
    const item = itemFor(line);

    if (!item) return;

    line.uom_id = line.uom_id || item.base_uom_id;
    line.rate = line.rate || item.std_rate;
}

function addLine() {
    form.lines = [...form.lines, blankLine()];
}

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
}

const lineValues = computed(() => form.lines.map((line) => (Number(line.qty) || 0) * (Number(line.rate) || 0)));
const goodsValue = computed(() => lineValues.value.reduce((sum, value) => sum + value, 0));
const landed = computed(
    () => (Number(form.freight_amount) || 0) + (Number(form.duty_amount) || 0) + (Number(form.clearing_amount) || 0),
);

/**
 * BR-36 — landed cost is apportioned to lines **by value**, not by quantity. A kilo of
 * imported UK ink and a kilo of local carton board do not carry the same share of the duty.
 */
function landedShare(index) {
    if (landed.value <= 0 || goodsValue.value <= 0) return 0;

    return landed.value * (lineValues.value[index] / goodsValue.value);
}

function landedRate(line, index) {
    const quantity = Number(line.qty) || 0;

    return quantity > 0 ? (Number(line.rate) || 0) + landedShare(index) / quantity : 0;
}

function submit() {
    form.post('/grns');
}

const columns = [
    { key: 'item_id', label: 'Item', width: '16rem' },
    { key: 'qty', label: 'Quantity', width: '11rem', align: 'right' },
    { key: 'rate', label: 'Rate', width: '8rem', align: 'right' },
    { key: 'landed_rate', label: 'Landed rate', width: '8rem', align: 'right' },
    { key: 'lot', label: 'Lot identity', width: '14rem' },
    { key: 'cert', label: 'Certification claim', width: '16rem' },
];
</script>

<template>
    <AppLayout>
        <Head title="New goods receipt" />

        <template #title>New goods receipt</template>
        <template #subtitle>Certification enters the system here — nothing downstream may invent a claim</template>

        <FormLayout @submit="submit">

            <Card title="Delivery">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <FormField label="Supplier" :error="form.errors.supplier_id" required>
                        <SelectInput
                            v-model="form.supplier_id"
                            placeholder="— select —"
                            :options="suppliers"
                            value-key="id"
                            label-key="name"
                        />
                    </FormField>

                    <FormField label="Against purchase order" :error="form.errors.po_id">
                        <SelectInput
                            v-model="form.po_id"
                            :options="availableOrders"
                            value-key="id"
                            label-key="number"
                        />
                    </FormField>

                    <FormField label="Into warehouse" :error="form.errors.warehouse_id" required>
                        <SelectInput
                            v-model="form.warehouse_id"
                            placeholder="— select —"
                            :options="warehouses"
                            value-key="id"
                            label-key="name"
                        />
                    </FormField>

                    <FormField label="Received on" :error="form.errors.received_on" required>
                        <DateInput v-model="form.received_on" />
                    </FormField>

                    <FormField label="Supplier invoice" :error="form.errors.invoice_no">
                        <TextInput v-model="form.invoice_no" />
                    </FormField>

                    <FormField label="Supplier challan" :error="form.errors.challan_no">
                        <TextInput v-model="form.challan_no" />
                    </FormField>
                </div>
            </Card>

            <Card
                title="Landed cost"
                rule="BR-36"
                subtitle="Apportioned to lines by value before the weighted average moves — mandatory when yarn and ink are imported"
            >
                <div class="grid gap-3 sm:grid-cols-4">
                    <FormField label="Freight" :error="form.errors.freight_amount">
                        <TextInput v-model="form.freight_amount" type="number" step="0.0001" numeric />
                    </FormField>

                    <FormField label="Duty" :error="form.errors.duty_amount">
                        <TextInput v-model="form.duty_amount" type="number" step="0.0001" numeric />
                    </FormField>

                    <FormField label="Clearing" :error="form.errors.clearing_amount">
                        <TextInput v-model="form.clearing_amount" type="number" step="0.0001" numeric />
                    </FormField>

                    <div class="rounded-md bg-slate-50 px-3 py-2">
                        <p class="text-xs text-ink-500">To apportion</p>
                        <p class="text-lg font-semibold tnum text-ink-900">{{ money(landed) }}</p>
                    </div>
                </div>
            </Card>

            <Card title="Lines" rule="I5 · Gate 2" :padded="false">
                <div class="p-3">
                    <LineItemsTable
                        :columns="columns"
                        :lines="form.lines"
                        :errors="form.errors"
                        add-label="Add line"
                        empty="No lines yet"
                    empty-hint="Each line becomes a barcoded lot. Any certification claim entered here is the only legitimate origin of that claim (Gate 2)."
                        @add="addLine"
                        @remove="removeLine"
                    >
                        <template #cell:item_id="{ line }">
                            <SelectInput
                                v-model="line.item_id"
                                placeholder="— item —"
                                :options="items"
                                value-key="id"
                                label-key="code"
                                @update:model-value="onItemChange(line)"
                            />
                            <p v-if="itemFor(line)" class="mt-1 truncate text-[11px] text-ink-500">
                                {{ itemFor(line).name }}
                            </p>
                        </template>

                        <template #cell:qty="{ line }">
                            <div class="flex gap-1">
                                <TextInput cell v-model="line.qty" type="number" step="0.000001" numeric />
                                <SelectInput
                                    v-model="line.uom_id"
                                    :placeholder="null"
                                    :options="uoms"
                                    value-key="id"
                                    label-key="code"
                                    class="w-24"
                                />
                            </div>
                        </template>

                        <template #cell:rate="{ line }">
                            <TextInput cell v-model="line.rate" type="number" step="0.0001" numeric />
                        </template>

                        <template #cell:landed_rate="{ line, index }">
                            <div class="text-right">
                                <span class="text-sm font-medium tnum text-ink-900">
                                    {{ money(landedRate(line, index)) }}
                                </span>
                                <p class="text-[10px] text-ink-400">+{{ money(landedShare(index)) }}</p>
                            </div>
                        </template>

                        <template #cell:lot="{ line }">
                            <div class="space-y-1">
                                <TextInput cell v-model="line.supplier_batch_no" placeholder="Supplier batch" />
                                <!-- BR-37: a shade code is what makes shade-first issue possible later. -->
                                <TextInput cell
                                    v-model="line.shade_code"
                                    :placeholder="itemFor(line)?.is_shade_critical ? 'Shade — required for this item' : 'Shade'"
                                />
                                <TextInput cell v-model="line.roll_length_m" type="number" step="0.000001" placeholder="Roll length (m)" numeric />
                                <DateInput
                                    v-if="itemFor(line)?.has_expiry"
                                    v-model="line.expiry_date"
                                />
                            </div>
                        </template>

                        <template #cell:cert="{ line }">
                            <div class="space-y-1">
                                <SelectInput
                                    v-model="line.cert_scheme"
                                    placeholder="— no claim —"
                                    :options="schemes.map((s) => ({ value: s, label: s.replace('_', ' ') }))"
                                />
                                <template v-if="line.cert_scheme">
                                    <TextInput cell v-model="line.cert_claim_pct" type="number" step="0.01" placeholder="Claim %" numeric />
                                    <TextInput cell v-model="line.cert_document_no" placeholder="Certificate / TC number" />
                                </template>
                            </div>
                        </template>

                        <template #rail>
                <Card title="Receipt" rule="BR-36">
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Lines</dt>
                            <dd class="tnum text-ink-900">{{ form.lines.length }}</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Goods value</dt>
                            <dd class="tnum text-ink-900">{{ money(goodsValue) }}</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Landed cost</dt>
                            <dd class="tnum text-ink-900">{{ money(landed) }}</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-2.5">
                            <dt class="text-xs text-ink-500">Into stock at</dt>
                            <dd class="text-base font-semibold tnum text-ink-900">
                                {{ money(goodsValue + landed) }}
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-[11px] leading-relaxed text-ink-500">
                        The landed cost is apportioned to the lines by value before the weighted
                        average moves, so the lot carries the true rate rather than the invoice one.
                    </p>
                </Card>
            </template>

            <template #footer>
                            <tr>
                                <td colspan="3" class="px-3 py-2 text-right text-xs text-ink-700">Goods value</td>
                                <td class="px-2 py-2 text-right text-sm font-semibold tnum text-ink-900">
                                    {{ money(goodsValue) }}
                                </td>
                                <td colspan="3" />
                            </tr>
                        </template>
                    </LineItemsTable>

                    <p v-if="form.errors.lines" class="mt-2 text-xs text-rose-600">{{ form.errors.lines }}</p>

                    <div class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900">
                        <span class="font-medium">On posting:</span> each line becomes a barcoded lot with a
                        <code class="font-mono">grn_receipt</code> ledger row, the item's weighted average moves
                        (BR-36), and any certification claim is written to the chain-of-custody ledger as certified
                        input (BR-42). This is the only legitimate origin of a claim.
                    </div>
                </div>
            </Card>

            <template #footer>
                <FormFooter
                    :form="form"
                    cancel-href="/grns"
                    :label="'Receive & post'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
