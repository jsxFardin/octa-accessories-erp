<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useBookedRate } from '@/composables/useBookedRate';
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
import { addCalendarDays, date, isoDate, money, pcs, rate, todayIso, typed } from '@/plugins/formatting';

const props = defineProps({
    /** `{ [product_id]: [{ min_qty, rate_per_m, list_code, currency }] }` from the lists current today. */
    listRates: { type: Object, default: () => ({}) },
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
    // Stored decimals load as typed numbers: "1000.0000" in a quantity cell is the database talking.
    lines: props.order?.lines?.length
        ? props.order.lines.map((line) => ({
            ...line,
            promised_date: isoDate(line.promised_date),
            ordered_qty: typed(line.ordered_qty),
            rate_per_m: typed(line.rate_per_m),
            tooling_charge: typed(line.tooling_charge) || 0,
            over_tolerance_pct: typed(line.over_tolerance_pct),
            under_tolerance_pct: typed(line.under_tolerance_pct),
        }))
        : [blankLine()],
});

/*
 * The contract rate, when the customer has one: the break with the highest starting quantity
 * at or below the ordered quantity, on a list that is current today and in this order's
 * currency. Offered, not imposed — a typed rate is kept.
 */
function listRateFor(line) {
    const breaks = props.listRates?.[line.product_id] ?? [];
    const qty = Number(line.ordered_qty) || 0;
    const applicable = breaks.filter((b) => Number(b.min_qty) <= qty || qty === 0).sort((a, b) => Number(b.min_qty) - Number(a.min_qty))[0] ?? breaks[0] ?? null;

    return applicable;
}

function listRateHint(line) {
    const found = listRateFor(line);

    if (!found) return null;
    if (found.currency !== currencyCode.value) return { text: `${found.list_code} lists ${rate(found.rate_per_m, found.currency)}; this order is in ${currencyCode.value}.`, applied: false };
    if (Number(line.rate_per_m) === Number(found.rate_per_m)) return { text: `Contract rate from ${found.list_code}.`, applied: true };

    return { text: `${found.list_code} lists ${rate(found.rate_per_m, found.currency)}.`, applied: false, rate: found.rate_per_m };
}

function applyListRate(line) {
    const found = listRateFor(line);

    if (found && found.currency === currencyCode.value) line.rate_per_m = typed(found.rate_per_m);
}

/* Delivery is usually four to eight weeks out; counted from the order date. */
const DELIVERY_PRESETS = [30, 45, 60];

function presetDelivery(days) {
    form.delivery_date = addCalendarDays(form.order_date || todayIso(), days);
}

/** The rate follows the currency: filled from the rate on file, and no field at all for base. */
const { isBase: baseCurrencyDocument, rateHint } = useBookedRate(form, () => props.currencies, { existing: Boolean(props.order) });

const availableProducts = computed(() =>
    form.customer_id
        ? props.products.filter((product) => product.customer_id === Number(form.customer_id))
        : props.products,
);

function productOf(line) {
    return props.products.find((product) => product.id === Number(line.product_id)) ?? null;
}

/**
 * The line follows its product's current specification. Nobody picks a version on this form,
 * so the id is set here rather than left blank for the server to refuse; the server settles
 * it again on save.
 */
function onProductChange(line) {
    line.product_spec_id = productOf(line)?.current_spec?.id ?? '';

    // An empty rate takes the contract rate; a typed one is left alone.
    if (!line.rate_per_m) applyListRate(line);
}

/** Products on the order that cannot be ordered yet, said before the save rather than after. */
const linesWithoutSpec = computed(() => form.lines
    .map((line, index) => ({ line, index, product: productOf(line) }))
    .filter(({ product }) => product && !product.current_spec));

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

/** A row nobody typed into — the blank one a new order starts with, or a stray "Add line". */
function isBlank(line) {
    return !line.id && !line.product_id && !line.ordered_qty && !line.rate_per_m;
}

function submit() {
    // Blank rows are dropped rather than failing the save with "line 3 product is required".
    form.transform((data) => ({ ...data, lines: data.lines.filter((line) => !isBlank(line)) }));

    isEdit.value
        ? form.put(`/sales-orders/${props.order.id}`)
        : form.post('/sales-orders');
}

/**
 * Products belong to one customer. Changing the customer narrowed the picker but left the
 * previous customer's products on the lines, where they could be saved.
 */
const droppedProducts = ref(0);

watch(() => form.customer_id, (customer) => {
    droppedProducts.value = 0;

    if (!customer) return;

    form.lines.forEach((line) => {
        const product = productOf(line);

        // A line with production against it keeps its product: that is history, not a typo.
        if (product && product.customer_id !== Number(customer) && !(Number(line.produced_qty) > 0)) {
            line.product_id = '';
            line.product_spec_id = '';
            droppedProducts.value += 1;
        }
    });
});

const columns = [
    { key: 'product_id', label: 'Product', width: '15rem', errorKeys: ['product_id', 'product_spec_id'] },
    { key: 'ordered_qty', label: 'Ordered', width: '8rem', align: 'right' },
    { key: 'rate_per_m', label: 'Rate per 1,000 pcs', width: '8rem', align: 'right' },
    { key: 'tolerance', label: 'Tolerance −/+ %', width: '10rem', errorKeys: ['under_tolerance_pct', 'over_tolerance_pct'] },
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
            A draft is not a commitment — confirmation is where the artwork, specification and credit checks apply.
        </template>

        <FormLayout @submit="submit">

            <!-- S2: no silent edits after confirmation. -->
            <Card
                v-if="isConfirmed"
                title="Amendment reason"
                rule="S2"
                subtitle="This order is confirmed. Every quantity or date change is recorded against your name."
            >
                <FormField label="Reason for the amendment" :error="form.errors.amendment_reason" required>
                    <TextInput v-model="form.amendment_reason" placeholder="Customer moved the shipment to week 42" />
                </FormField>
            </Card>

            <Card title="Order details">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <!-- Two columns: a code and a name do not fit in one, and the name is what is read. -->
                    <FormField label="Customer" class="sm:col-span-2" :error="form.errors.customer_id" required>
                        <SelectInput
                            v-model="form.customer_id"
                            placeholder="— select —"
                            :options="customers"
                            value-key="id"
                            label-key="name"
                        />
                    </FormField>

                    <FormField label="Customer PO number" :error="form.errors.customer_po_no" hint="As it reads on their purchase order.">
                        <TextInput v-model="form.customer_po_no" placeholder="LPO-26-101" />
                    </FormField>

                    <FormField label="Order date" :error="form.errors.order_date" required>
                        <DateInput v-model="form.order_date" />
                    </FormField>

                    <FormField label="Delivery date" :error="form.errors.delivery_date">
                        <DateInput v-model="form.delivery_date" :min="form.order_date" />
                        <div class="mt-1 flex items-center gap-0.5 text-xs text-ink-500">
                            <button v-for="days in DELIVERY_PRESETS" :key="days" type="button" class="min-h-5 rounded px-1.5 text-brand-700 transition hover:bg-brand-50 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none" :title="`${days} days from the order date`" @click="presetDelivery(days)">+{{ days }}d</button>
                        </div>
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

                    <FormField v-if="!baseCurrencyDocument" label="Exchange rate" :hint="rateHint" :error="form.errors.exchange_rate" required>
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
                    empty-hint="Each line needs a product with a current spec and an approved artwork before the order can be confirmed."
                        @add="addLine"
                        @remove="removeLine"
                    >
                        <template #cell:product_id="{ line }">
                            <SelectInput
                                v-model="line.product_id"
                                class="min-w-40"
                                placeholder="— product —"
                                :options="availableProducts"
                                value-key="id"
                                label-key="code"
                                hint-key="name"
                                @update:model-value="onProductChange(line)"
                            />
                            <p v-if="productOf(line)?.current_spec" class="mt-1 text-xs text-ink-500">
                                Specification v{{ productOf(line).current_spec.version_no }}
                            </p>
                            <p v-else-if="productOf(line)" class="mt-1 text-xs text-rose-700">
                                No current specification.
                                <a :href="`/products/${line.product_id}`" target="_blank" rel="noopener" class="font-medium underline">Open the product</a>
                                to add one.
                            </p>
                            <p v-if="Number(line.produced_qty) > 0" class="mt-1 text-xs text-ink-500">
                                {{ pcs(line.produced_qty) }} produced — line cannot be removed
                            </p>
                        </template>

                        <template #cell:ordered_qty="{ line }">
                            <TextInput cell v-model="line.ordered_qty" type="number" numeric min="1" placeholder="0" class="min-w-24" @change="!line.rate_per_m && applyListRate(line)" />
                        </template>

                        <template #cell:rate_per_m="{ line }">
                            <TextInput cell v-model="line.rate_per_m" type="number" step="0.0001" numeric placeholder="0.0000" class="min-w-24" />
                            <!-- The contract rate, said beside the cell: taken when the cell was empty, offered when it was not. -->
                            <p v-if="listRateHint(line)" class="mt-1 text-xs" :class="listRateHint(line).applied ? 'text-emerald-700' : 'text-ink-500'">
                                {{ listRateHint(line).text }}
                                <button v-if="listRateHint(line).rate !== undefined" type="button" class="font-medium text-brand-700 underline" @click="applyListRate(line)">Use it</button>
                            </p>
                        </template>

                        <template #cell:tolerance="{ line }">
                            <div class="flex gap-1">
                                <TextInput cell v-model="line.under_tolerance_pct" type="number" step="0.01" numeric placeholder="−%" class="min-w-14" aria-label="Under-delivery tolerance %" />
                                <TextInput cell v-model="line.over_tolerance_pct" type="number" step="0.01" numeric placeholder="+%" class="min-w-14" aria-label="Over-delivery tolerance %" />
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
                            <span class="text-sm tnum text-ink-800">{{ money(lineTotal(line), currencyCode) }}</span>
                        </template>

                        <template #footer>
                            <tr>
                                <td colspan="6" class="px-3 py-2 text-right text-xs text-ink-700">Subtotal</td>
                                <td class="px-2 py-2 text-right text-sm font-semibold tnum text-ink-900">
                                    {{ money(subtotal, currencyCode) }}
                                </td>
                                <td />
                            </tr>
                        </template>
                    </LineItemsTable>

                    <p v-if="droppedProducts" role="status" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        {{ droppedProducts }} {{ droppedProducts === 1 ? 'line had a product' : 'lines had products' }}
                        belonging to the previous customer. {{ droppedProducts === 1 ? 'It has' : 'They have' }} been
                        cleared — choose again from this customer's products.
                    </p>

                    <p v-if="form.errors.lines" class="mt-2 text-xs text-rose-600">{{ form.errors.lines }}</p>

                    <p class="mt-2 text-xs text-ink-500">
                        Promised dates are computed on confirmation from the delivery date, QC and
                        packing days, and the address transit time — leave them blank to
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

                    <p class="mt-3 text-xs leading-relaxed text-ink-500">
                        Ordered quantity, not shipped: each line may land inside its own
                        tolerance band, which the Lines table shows per line.
                    </p>
                </Card>

                <Card title="Notes">
                    <FormField label="Order notes" :error="form.errors.notes">
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
                    :disabled="linesWithoutSpec.length > 0"
                    :disabled-reason="linesWithoutSpec.length ? `Line ${linesWithoutSpec.map(({ index }) => index + 1).join(', ')}: the product has no current specification yet.` : null"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
