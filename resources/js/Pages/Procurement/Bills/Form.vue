<script setup>
import { ref, computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useBookedRate } from '@/composables/useBookedRate';
import LineItemsTable from '@/Components/Ui/LineItemsTable.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money, todayIso, typedRecord } from '@/plugins/formatting';

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    purchaseOrders: { type: Array, default: () => [] },
    grns: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    prefill: { type: Object, default: null },
    /** Materials for the line picker. */
    items: { type: Array, default: () => [] },
    /** A draft bill being corrected; null for a new one. */
    bill: { type: Object, default: null },
});

const isEdit = computed(() => Boolean(props.bill));

const emptyLine = () => ({ item_id: null, description: '', qty: null, rate: null, tax_id: null });

const form = useForm({
    supplier_id: props.bill?.supplier_id ?? props.prefill?.supplier_id ?? null,
    po_id: props.bill?.po_id ?? props.prefill?.po_id ?? null,
    grn_id: props.bill?.grn_id ?? props.prefill?.grn_id ?? null,
    bill_no: props.bill?.bill_no ?? '',
    bill_date: props.bill?.bill_date ?? todayIso(),
    due_date: props.bill?.due_date ?? '',
    currency_id: props.bill?.currency_id ?? props.prefill?.currency_id ?? null,
    exchange_rate: props.bill?.exchange_rate ?? 1,
    lines: props.bill?.lines?.length ? props.bill.lines.map((line) => typedRecord(line)) : (props.prefill?.lines ?? [emptyLine()]),
});

/** The rate follows the currency: filled from the rate on file, and no field at all for base. */
const { isBase: baseCurrencyDocument, rateHint } = useBookedRate(form, () => props.currencies, { existing: Boolean(props.bill) });

const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.id, label: `${s.code} — ${s.name}` })));
const poOptions = computed(() => props.purchaseOrders.filter((po) => !form.supplier_id || po.supplier_id === form.supplier_id).map((po) => ({ value: po.id, label: po.number })));
const grnOptions = computed(() => props.grns.filter((g) => !form.supplier_id || g.supplier_id === form.supplier_id).map((g) => ({ value: g.id, label: g.number })));
const currencyOptions = computed(() => props.currencies.map((c) => ({ value: c.id, label: `${c.code} — ${c.name}` })));

function lineAmount(line) {
    return line.qty && line.rate ? Number(line.qty) * Number(line.rate) : 0;
}

const currencyCode = computed(() => props.currencies.find((row) => row.id === Number(form.currency_id))?.code);

/**
 * A line with a material on it can be matched against the order and the goods receipt; one
 * with only a description cannot. Choosing the material fills the description when it is blank.
 */
function onItemChange(line) {
    const item = props.items.find((row) => row.id === Number(line.item_id));

    if (item && !String(line.description ?? '').trim()) line.description = item.name;
}

const lineColumns = [
    { key: 'item_id', label: 'Material', width: '15rem' },
    { key: 'description', label: 'Description' },
    { key: 'qty', label: 'Quantity', width: '8rem', align: 'right', required: true },
    { key: 'rate', label: 'Rate', width: '9rem', align: 'right', required: true },
    { key: 'amount', label: 'Amount', width: '10rem', align: 'right', errorKeys: ['tax_id'] },
];

const subtotal = computed(() => form.lines.reduce((sum, line) => sum + Number(lineAmount(line)), 0));

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    if (form.lines.length > 1) form.lines.splice(index, 1);
}

function submit() {
    isEdit.value
        ? form.put(`/supplier-bills/${props.bill.id}`, { preserveScroll: true })
        : form.post('/supplier-bills', { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${bill.number ?? bill.bill_no}` : 'New supplier bill'" />

        <template #title>{{ isEdit ? `Edit draft bill ${bill.number ?? bill.bill_no}` : 'New supplier bill' }}</template>
        <template #subtitle>
            Enter what the supplier has billed. Link the purchase order and goods receipt so the bill can be checked against them.
        </template>

        <FormLayout @submit="submit">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <FormField label="Supplier" :error="form.errors.supplier_id" required>
                        <SelectInput v-model="form.supplier_id" :options="supplierOptions" placeholder="Choose supplier…" />
                    </FormField>
                    <FormField label="Purchase order" :error="form.errors.po_id">
                        <SelectInput v-model="form.po_id" :options="poOptions" placeholder="Optional…" clearable />
                    </FormField>
                    <FormField label="Goods receipt (GRN)" :error="form.errors.grn_id">
                        <SelectInput v-model="form.grn_id" :options="grnOptions" placeholder="Optional…" clearable />
                    </FormField>
                    <FormField label="Supplier bill no." :error="form.errors.bill_no" required>
                        <TextInput v-model="form.bill_no" />
                    </FormField>
                    <FormField label="Bill date" :error="form.errors.bill_date" required>
                        <DateInput v-model="form.bill_date" />
                    </FormField>
                    <FormField label="Due date" :error="form.errors.due_date">
                        <DateInput v-model="form.due_date" />
                    </FormField>
                    <FormField label="Currency" :error="form.errors.currency_id" required>
                        <SelectInput v-model="form.currency_id" :options="currencyOptions" placeholder="Choose…" />
                    </FormField>
                    <FormField v-if="!baseCurrencyDocument" label="Exchange rate" :hint="rateHint" :error="form.errors.exchange_rate">
                        <TextInput v-model="form.exchange_rate" type="number" min="0" step="any" numeric />
                    </FormField>
                </div>

                <Card title="Lines" class="mt-4">
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
                                placeholder="— not a stock material —"
                                :options="items"
                                value-key="id"
                                label-key="code"
                                hint-key="name"
                                clearable
                                @update:model-value="onItemChange(line)"
                            />
                        </template>
                        <template #cell:description="{ line }">
                            <TextInput v-model="line.description" cell placeholder="As it reads on the supplier's bill" />
                        </template>
                        <template #cell:qty="{ line }">
                            <TextInput v-model="line.qty" cell type="number" min="0" step="any" numeric />
                        </template>
                        <template #cell:rate="{ line }">
                            <TextInput v-model="line.rate" cell type="number" min="0" step="any" numeric />
                        </template>
                        <template #cell:amount="{ line }">
                            <span class="block pt-1 text-right tnum text-ink-900">{{ money(lineAmount(line), currencyCode) }}</span>
                        </template>

                        <template #footer>
                            <tr>
                                <td colspan="5" class="px-1.5 py-2 text-right text-xs text-ink-700">Bill total</td>
                                <td class="px-1.5 py-2 text-right text-sm font-semibold tnum text-ink-900">{{ money(subtotal, currencyCode) }}</td>
                                <td />
                            </tr>
                        </template>
                    </LineItemsTable>

                    <p class="mt-2 text-xs text-ink-600">
                        A line with a material chosen is checked against the purchase order and the goods receipt
                        when the bill is approved. A line without one — a service, a courier charge — is not.
                    </p>
                </Card>

            <template #footer>
                <FormFooter
                    :form="form"
                    :cancel-href="isEdit ? `/supplier-bills/${bill.id}` : '/supplier-bills'"
                    :label="isEdit ? 'Save changes' : 'Save bill'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
