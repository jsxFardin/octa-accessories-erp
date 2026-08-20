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
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import { date, isoDate, money, pcs, todayIso } from '@/plugins/formatting';

const props = defineProps({
    priorities: { type: Array, default: () => [] },
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
    order_date: isoDate(props.order?.order_date) || todayIso(),
    delivery_date: isoDate(props.order?.delivery_date),
    currency_id: props.order?.currency_id ?? props.currencies.find((c) => c.is_base)?.id ?? '',
    exchange_rate: props.order?.exchange_rate ?? 1,
    priority: props.order?.priority ?? 'normal',
    notes: props.order?.notes ?? '',
    amendment_reason: '',
    lines: props.order?.lines?.length
        ? props.order.lines.map((line) => ({ ...line, promised_date: isoDate(line.promised_date) }))
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

const orderedQty = computed(() =>
    form.lines.reduce((sum, line) => sum + (Number(line.ordered_qty) || 0), 0),
);

/** A line with neither a product nor a quantity is a row somebody has not filled in yet. */
const filledLines = computed(() => form.lines.filter((line) => line.product_id || line.ordered_qty).length);

const selectedCustomer = computed(
    () => props.customers.find((customer) => String(customer.id) === String(form.customer_id)) ?? null,
);

const currencyCode = computed(
    () => props.currencies.find((currency) => String(currency.id) === String(form.currency_id))?.code ?? '',
);

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

        <FormLayout @submit="submit">

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
                        <SelectInput v-model="form.priority" :placeholder="null" :options="priorities" />
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
                        empty="No lines yet"
                    empty-hint="Each line needs a product with a current spec and an approved artwork before the order can be confirmed (S3)."
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
                            <TextInput cell v-model="line.ordered_qty" type="number" numeric min="1" />
                        </template>

                        <template #cell:rate_per_m="{ line }">
                            <TextInput cell v-model="line.rate_per_m" type="number" step="0.0001" numeric />
                        </template>

                        <template #cell:tolerance="{ line }">
                            <div class="flex gap-1">
                                <TextInput cell v-model="line.under_tolerance_pct" type="number" step="0.01" numeric />
                                <TextInput cell v-model="line.over_tolerance_pct" type="number" step="0.01" numeric />
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

            <template #rail>
                <Card title="Order">
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Customer</dt>
                            <dd class="min-w-0 truncate text-right" :class="selectedCustomer ? 'text-ink-900' : 'text-ink-400'">
                                {{ selectedCustomer?.name ?? 'Not chosen' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Delivery</dt>
                            <dd class="text-right" :class="form.delivery_date ? 'text-ink-900' : 'text-ink-400'">
                                {{ form.delivery_date ? date(form.delivery_date) : 'Not set' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-2.5">
                            <dt class="text-xs text-ink-500">Lines</dt>
                            <dd class="tnum text-ink-900">{{ filledLines }}</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Ordered</dt>
                            <dd class="tnum text-ink-900">{{ pcs(orderedQty) }} pcs</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-2.5">
                            <dt class="text-xs text-ink-500">Order value</dt>
                            <dd class="text-base font-semibold tnum text-ink-900">
                                {{ money(subtotal, currencyCode) }}
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-[11px] leading-relaxed text-ink-500">
                        Ordered quantity, not shipped: BR-44 lets each line land inside its own
                        tolerance band, which the Lines table shows per line.
                    </p>
                </Card>

                <Card title="Notes">
                    <FormField :error="form.errors.notes">
                        <textarea
                            v-model="form.notes"
                            rows="8"
                            class="form-textarea"
                            placeholder="Anything the planner or the packer needs to know."
                        />
                    </FormField>
                </Card>
            </template>

            <template #footer>
                <FormFooter
                    :form="form"
                    cancel-href="/sales-orders"
                    :summary="`${filledLines} ${filledLines === 1 ? 'line' : 'lines'} · ${money(subtotal, currencyCode)}`"
                    :label="isEdit ? 'Save changes' : 'Save draft'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
