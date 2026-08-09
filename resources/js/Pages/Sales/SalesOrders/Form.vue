<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import LineItemsTable from '@/Components/Ui/LineItemsTable.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { money, pcs } from '@/plugins/formatting';

const props = defineProps({
    order: { type: Object, default: null },
    customers: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    defaults: { type: Object, default: () => ({ over_tolerance_pct: 5, under_tolerance_pct: 5 }) },
});

const isEdit = computed(() => Boolean(props.order));

/** S2 — once confirmed, every quantity or date change needs a documented reason. */
const isConfirmed = computed(
    () => isEdit.value && !['draft', 'credit_hold'].includes(props.order.status),
);

function blankLine() {
    return {
        id: null,
        product_id: '',
        product_spec_id: '',
        description: '',
        ordered_qty: '',
        rate_per_m: '',
        tooling_charge: 0,
        over_tolerance_pct: Number(props.defaults.over_tolerance_pct),
        under_tolerance_pct: Number(props.defaults.under_tolerance_pct),
        promised_date: '',
        produced_qty: 0,
    };
}

const form = useForm({
    customer_id: props.order?.customer_id ?? '',
    customer_po_no: props.order?.customer_po_no ?? '',
    order_date: props.order?.order_date ?? new Date().toISOString().slice(0, 10),
    delivery_date: props.order?.delivery_date ?? '',
    currency_id: props.order?.currency_id ?? props.currencies.find((c) => c.is_base)?.id ?? '',
    exchange_rate: props.order?.exchange_rate ?? 1,
    priority: props.order?.priority ?? 'normal',
    notes: props.order?.notes ?? '',
    amendment_reason: '',
    lines: props.order?.lines?.length
        ? props.order.lines.map((line) => ({ ...line }))
        : [blankLine()],
});

const availableProducts = computed(() =>
    form.customer_id
        ? props.products.filter((product) => product.customer_id === Number(form.customer_id))
        : props.products,
);

function addLine() {
    form.lines = [...form.lines, blankLine()];
}

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
}

/** S1 — a line with production against it cannot simply be dropped. */
function canRemove(line) {
    return !(Number(line.produced_qty) > 0);
}

function lineTotal(line) {
    return (Number(line.ordered_qty) || 0) / 1000 * (Number(line.rate_per_m) || 0)
        + (Number(line.tooling_charge) || 0);
}

const subtotal = computed(() => form.lines.reduce((sum, line) => sum + lineTotal(line), 0));

/** BR-44 — the band the shipment has to land inside, shown while the order is written. */
function band(line) {
    const ordered = Number(line.ordered_qty) || 0;

    return {
        min: Math.round(ordered * (1 - (Number(line.under_tolerance_pct) || 0) / 100)),
        max: Math.round(ordered * (1 + (Number(line.over_tolerance_pct) || 0) / 100)),
    };
}

function submit() {
    isEdit.value
        ? form.put(`/sales-orders/${props.order.id}`)
        : form.post('/sales-orders');
}

const columns = [
    { key: 'product_id', label: 'Product', width: '15rem' },
    { key: 'ordered_qty', label: 'Ordered', width: '8rem', align: 'right' },
    { key: 'rate_per_m', label: 'Rate /M', width: '8rem', align: 'right' },
    { key: 'tolerance', label: 'Tolerance −/+ %', width: '10rem' },
    { key: 'band', label: 'Acceptable band', width: '10rem', align: 'right' },
    { key: 'promised_date', label: 'Promised', width: '9rem' },
    { key: 'line_total', label: 'Value', width: '9rem', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit sales order' : 'New sales order'" />

        <template #title>
            {{ isEdit ? `Order ${order.number ?? '(unnumbered)'}` : 'New sales order' }}
        </template>
        <template #subtitle>
            A draft is not a commitment — confirmation is where Gate 1 and the credit check apply.
        </template>

        <template #actions>
            <Button href="/sales-orders">Cancel</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">
                {{ isEdit ? 'Save changes' : 'Save draft' }}
            </Button>
        </template>

        <form class="space-y-4" @submit.prevent="submit">
            <!-- S2: no silent edits after confirmation. -->
            <Card
                v-if="isConfirmed"
                title="Amendment reason"
                rule="S2"
                subtitle="This order is confirmed. Every quantity or date change is recorded against your name."
            >
                <FormField :error="form.errors.amendment_reason" required>
                    <TextInput v-model="form.amendment_reason" placeholder="Customer moved the shipment to week 42" />
                </FormField>
            </Card>

            <Card title="Header">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Customer" :error="form.errors.customer_id" required>
                        <SelectInput
                            v-model="form.customer_id"
                            placeholder="— select —"
                            :options="customers"
                            value-key="id"
                            label-key="name"
                        />
                    </FormField>

                    <FormField label="Customer PO number" :error="form.errors.customer_po_no">
                        <TextInput v-model="form.customer_po_no" />
                    </FormField>

                    <FormField label="Order date" :error="form.errors.order_date" required>
                        <DateInput v-model="form.order_date" />
                    </FormField>

                    <FormField label="Delivery date" :error="form.errors.delivery_date">
                        <DateInput v-model="form.delivery_date" />
                    </FormField>

                    <FormField label="Currency" :error="form.errors.currency_id" required>
                        <SelectInput
                            v-model="form.currency_id"
                            :placeholder="null"
                            :options="currencies"
                            value-key="id"
                            label-key="code"
                        />
                    </FormField>

                    <FormField label="Exchange rate" :error="form.errors.exchange_rate" required>
                        <TextInput v-model="form.exchange_rate" type="number" step="0.000001" numeric />
                    </FormField>

                    <FormField label="Priority" :error="form.errors.priority" required>
                        <SelectInput
                            v-model="form.priority"
                            :placeholder="null"
                            :options="[
                                { value: 'low', label: 'Low' },
                                { value: 'normal', label: 'Normal' },
                                { value: 'high', label: 'High' },
                                { value: 'urgent', label: 'Urgent' },
                            ]"
                        />
                    </FormField>
                </div>
            </Card>

            <Card title="Lines" rule="BR-1 · BR-44" :padded="false">
                <div class="p-3">
                    <LineItemsTable
                        :columns="columns"
                        :lines="form.lines"
                        :errors="form.errors"
                        :can-remove="canRemove"
                        add-label="Add line"
                        empty="An order needs at least one line."
                        @add="addLine"
                        @remove="removeLine"
                    >
                        <template #cell:product_id="{ line }">
                            <SelectInput
                                v-model="line.product_id"
                                placeholder="— product —"
                                :options="availableProducts"
                                value-key="id"
                                label-key="code"
                            />
                            <p v-if="Number(line.produced_qty) > 0" class="mt-1 text-[11px] text-ink-500">
                                {{ pcs(line.produced_qty) }} produced — line cannot be removed (S1)
                            </p>
                        </template>

                        <template #cell:ordered_qty="{ line }">
                            <TextInput v-model="line.ordered_qty" type="number" numeric min="1" />
                        </template>

                        <template #cell:rate_per_m="{ line }">
                            <TextInput v-model="line.rate_per_m" type="number" step="0.0001" numeric />
                        </template>

                        <template #cell:tolerance="{ line }">
                            <div class="flex gap-1">
                                <TextInput v-model="line.under_tolerance_pct" type="number" step="0.01" numeric />
                                <TextInput v-model="line.over_tolerance_pct" type="number" step="0.01" numeric />
                            </div>
                        </template>

                        <template #cell:band="{ line }">
                            <span class="text-xs tnum text-ink-500">
                                {{ pcs(band(line).min) }}–{{ pcs(band(line).max) }}
                            </span>
                        </template>

                        <template #cell:promised_date="{ line }">
                            <DateInput v-model="line.promised_date" />
                        </template>

                        <template #cell:line_total="{ line }">
                            <span class="text-sm tnum text-ink-800">{{ money(lineTotal(line)) }}</span>
                        </template>

                        <template #footer>
                            <tr>
                                <td colspan="6" class="px-3 py-2 text-right text-xs text-ink-700">Subtotal</td>
                                <td class="px-2 py-2 text-right text-sm font-semibold tnum text-ink-900">
                                    {{ money(subtotal) }}
                                </td>
                                <td />
                            </tr>
                        </template>
                    </LineItemsTable>

                    <p v-if="form.errors.lines" class="mt-2 text-xs text-rose-600">{{ form.errors.lines }}</p>

                    <p class="mt-2 text-xs text-ink-500">
                        Promised dates are computed on confirmation from the delivery date, QC and
                        packing days, and the address transit time (BR-29) — leave them blank to
                        let the system fill them in.
                    </p>
                </div>
            </Card>

            <Card title="Notes">
                <FormField :error="form.errors.notes">
                    <textarea v-model="form.notes" rows="3" class="form-textarea" />
                </FormField>
            </Card>
        </form>
    </AppLayout>
</template>
