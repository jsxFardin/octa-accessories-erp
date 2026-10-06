<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import LineItemsTable from '@/Components/Ui/LineItemsTable.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import { addCalendarDays, date, isoDate, money, pcs, todayIso } from '@/plugins/formatting';

const props = defineProps({
    inquiry: { type: Object, default: null },
    /** The customer whose page this inquiry was started from, resolved server-side. */
    preselectCustomerId: { type: Number, default: null },
    customers: { type: Array, default: () => [] },
    /** Every customer's contacts and brands; the form shows the chosen customer's. */
    contacts: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    productTypes: { type: Array, default: () => [] },
    /** Active products, for a line that asks for something already made before. */
    products: { type: Array, default: () => [] },
    sources: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.inquiry));

const form = useForm({
    customer_id: props.inquiry?.customer_id ?? props.preselectCustomerId ?? '',
    customer_contact_id: props.inquiry?.customer_contact_id ?? '',
    brand_id: props.inquiry?.brand_id ?? '',
    inquiry_date: isoDate(props.inquiry?.inquiry_date) || todayIso(),
    required_by: isoDate(props.inquiry?.required_by),
    source: props.inquiry?.source ?? '',
    notes: props.inquiry?.notes ?? '',
    // Stored as decimals; typed as numbers. "12000.00" in a quantity cell is the database talking.
    lines: props.inquiry?.lines?.length
        ? props.inquiry.lines.map((line) => ({
            ...line,
            qty: line.qty === null || line.qty === '' ? '' : String(Number(line.qty)),
            target_rate_per_m: line.target_rate_per_m === null || line.target_rate_per_m === '' ? '' : String(Number(line.target_rate_per_m)),
        }))
        : [blankLine()],
});

function blankLine() {
    return { product_id: '', description: '', product_type: '', qty: '', target_rate_per_m: '', notes: '' };
}

function addLine() {
    form.lines = [...form.lines, blankLine()];
}

/**
 * Enter in the last cell of the last line starts the next one, with the cursor already in
 * its description. Line entry is keyboard work; reaching for "Add line" every row is not.
 */
function addLineFromKeyboard(index) {
    if (index !== form.lines.length - 1) return;

    addLine();
    nextTick(() => document.querySelector(`[aria-label="Description, line ${form.lines.length}"]`)?.focus());
}

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
}

const totalQty = computed(() =>
    form.lines.reduce((sum, line) => sum + (Number(line.qty) || 0), 0),
);

/** BR-1: the rate is per 1000 pieces, so a line is worth qty ÷ 1000 × rate. */
function lineValue(line) {
    return ((Number(line.qty) || 0) / 1000) * (Number(line.target_rate_per_m) || 0);
}

const totalValue = computed(() => form.lines.reduce((sum, line) => sum + lineValue(line), 0));

/** Only the lines a person actually typed something into count as filled in. */
const filledLines = computed(() => form.lines.filter((line) => line.description || line.qty).length);

const selectedCustomer = computed(
    () => props.customers.find((customer) => String(customer.id) === String(form.customer_id)) ?? null,
);

/**
 * BR-55 — an inquiry carries no currency of its own, so the value implied by a target rate is
 * understood in the currency the customer trades in. Unlabelled it read as the factory's, and
 * most of these customers are quoted in USD.
 */
const customerCurrency = computed(() => selectedCustomer.value?.currency ?? undefined);

const forCustomer = (rows) => (form.customer_id ? rows.filter((row) => row.customer_id === Number(form.customer_id)) : []);
const availableContacts = computed(() => forCustomer(props.contacts).map((c) => ({ ...c, label: c.designation ? `${c.name} · ${c.designation}` : c.name })));
const availableBrands = computed(() => forCustomer(props.brands));

/** Products belong to one customer, so the picker follows the customer chosen above. */
const availableProducts = computed(() => (form.customer_id
    ? props.products.filter((product) => product.customer_id === Number(form.customer_id))
    : props.products));

/**
 * Choosing an existing product describes the line for you. The line already carried a
 * `product_id` and the server already accepted one; there was simply no way to set it, so every
 * quotation raised from an inquiry asked for its products all over again.
 */
function onProductChange(line) {
    const product = props.products.find((row) => row.id === Number(line.product_id));

    if (!product) return;

    if (!String(line.description ?? '').trim()) line.description = product.name;
    if (!line.product_type) line.product_type = product.product_type ?? '';
}

/** A change of customer leaves the previous customer's products, contact and brand behind. */
const droppedProducts = ref(0);

watch(() => form.customer_id, (customer) => {
    droppedProducts.value = 0;

    if (!props.contacts.some((c) => String(c.id) === String(form.customer_contact_id) && c.customer_id === Number(customer))) form.customer_contact_id = '';
    if (!props.brands.some((b) => String(b.id) === String(form.brand_id) && b.customer_id === Number(customer))) form.brand_id = '';

    if (!customer) return;

    // One contact or one brand is not a choice: fill it in.
    if (!form.customer_contact_id && availableContacts.value.length === 1) form.customer_contact_id = availableContacts.value[0].id;
    if (!form.brand_id && availableBrands.value.length === 1) form.brand_id = availableBrands.value[0].id;

    form.lines.forEach((line) => {
        const product = props.products.find((row) => row.id === Number(line.product_id));

        if (product && product.customer_id !== Number(customer)) {
            line.product_id = '';
            droppedProducts.value += 1;
        }
    });
});

/*
 * Required-by is usually "four to eight weeks from now"; typing the date is the slow way.
 * Counted from the inquiry date, because that is what the customer's lead time runs from.
 */
const PRESETS = [30, 45, 60];

function presetRequiredBy(days) {
    form.required_by = addCalendarDays(form.inquiry_date || todayIso(), days);
}

const requiredByPast = computed(() => form.required_by && form.required_by < todayIso());

/** A row nobody typed into: the blank one a new form starts with, or a stray "Add line". */
function isBlank(line) {
    return !line.product_id && !String(line.description ?? '').trim() && !line.qty && !line.target_rate_per_m;
}

function submit() {
    // Blank rows are dropped rather than failing the save with "line 3 description is required".
    form.transform((data) => ({ ...data, lines: data.lines.filter((line) => !isBlank(line)) }));

    isEdit.value
        ? form.put(`/inquiries/${props.inquiry.id}`)
        : form.post('/inquiries');
}

/*
 * Description is the widest column: it is the one thing every line must carry, and every
 * other column is a pick-list or a number. The widths on the rest are ceilings, not shares.
 */
const columns = [
    { key: 'product_id', label: 'Existing product', width: '12rem' },
    { key: 'description', label: 'Description', required: true },
    { key: 'product_type', label: 'Type', width: '10rem' },
    { key: 'qty', label: 'Quantity (pcs)', width: '7.5rem', align: 'right' },
    { key: 'target_rate_per_m', label: 'Target rate per 1,000 pcs', width: '8.5rem', align: 'right' },
    { key: 'value', label: 'Indicative value', width: '8rem', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit inquiry' : 'New inquiry'" />

        <template #title>{{ isEdit ? `Inquiry ${inquiry.number ?? '(unnumbered)'}` : 'New inquiry' }}</template>
        <template #subtitle>
            A draft carries no number — one is assigned when it is submitted.
        </template>

        <FormLayout @submit="submit">
            <Card title="Who is asking">
                <div class="grid gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                    <FormField label="Customer" :error="form.errors.customer_id" required>
                        <SelectInput
                            v-model="form.customer_id"
                            placeholder="— select —"
                            :options="customers"
                            value-key="id"
                            label-key="name"
                            hint-key="code"
                        />
                    </FormField>

                    <FormField label="Contact" :error="form.errors.customer_contact_id" :hint="form.customer_id && !availableContacts.length ? 'No contacts on this customer yet.' : null">
                        <SelectInput
                            v-model="form.customer_contact_id"
                            placeholder="— none —"
                            :options="availableContacts"
                            value-key="id"
                            label-key="label"
                            :disabled="!form.customer_id || !availableContacts.length"
                        />
                    </FormField>

                    <FormField label="Brand" :error="form.errors.brand_id" :hint="form.customer_id && !availableBrands.length ? 'No brands on this customer yet.' : null">
                        <SelectInput
                            v-model="form.brand_id"
                            placeholder="— none —"
                            :options="availableBrands"
                            value-key="id"
                            label-key="name"
                            hint-key="code"
                            :disabled="!form.customer_id || !availableBrands.length"
                        />
                    </FormField>

                    <FormField label="Inquiry date" :error="form.errors.inquiry_date" required>
                        <DateInput v-model="form.inquiry_date" />
                    </FormField>

                    <FormField
                        label="Required by"
                        rule="BR-29"
                        :hint="requiredByPast ? null : 'Feeds the promised date once this becomes an order.'"
                        :error="form.errors.required_by"
                    >
                        <DateInput v-model="form.required_by" :min="form.inquiry_date" />
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="text-ink-500">From the inquiry date:</span>
                            <button
                                v-for="days in PRESETS"
                                :key="days"
                                type="button"
                                class="min-h-6 rounded border border-slate-200 px-2 text-ink-700 transition hover:border-brand-300 hover:bg-brand-50 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                @click="presetRequiredBy(days)"
                            >
                                +{{ days }} days
                            </button>
                        </div>
                        <p v-if="requiredByPast" class="mt-1 text-xs text-amber-700">This date has already passed.</p>
                    </FormField>

                    <FormField label="Source" :error="form.errors.source">
                        <!-- Editable in Setup → Vocabularies, like every other list. -->
                        <SelectInput v-model="form.source" :options="sources" />
                    </FormField>
                </div>
            </Card>

            <Card
                title="What they are asking for"
                subtitle="One line per label or tag. A quantity is enough to start; the spec comes later."
                :padded="false"
            >
                <template #actions>
                    <span class="text-xs text-ink-500">{{ filledLines }} {{ filledLines === 1 ? 'line' : 'lines' }}</span>
                </template>

                <div class="px-4 py-3">
                    <LineItemsTable
                        :columns="columns"
                        :lines="form.lines"
                        :errors="form.errors"
                        add-label="Add line"
                        empty="No lines yet"
                        empty-hint="What the customer asked for, in their words — a product record is not needed yet."
                        @add="addLine"
                        @remove="removeLine"
                    >
                        <template #cell:product_id="{ line }">
                            <SelectInput
                                v-model="line.product_id"
                                class="min-w-36"
                                placeholder="— new —"
                                :options="availableProducts"
                                value-key="id"
                                label-key="code"
                                hint-key="name"
                                @update:model-value="onProductChange(line)"
                            />
                        </template>

                        <template #cell:description="{ line }">
                            <TextInput cell v-model="line.description" class="min-w-48" placeholder="Centre-fold satin care label, 40 × 20 mm" />
                        </template>

                        <template #cell:product_type="{ line }">
                            <SelectInput v-model="line.product_type" :options="productTypes" />
                        </template>

                        <template #cell:qty="{ line }">
                            <TextInput cell v-model="line.qty" type="number" numeric min="1" placeholder="0" class="min-w-20" />
                        </template>

                        <template #cell:target_rate_per_m="{ line, index }">
                            <!-- BR-1: everything in this business is priced per 1000 pieces. -->
                            <TextInput
                                cell
                                v-model="line.target_rate_per_m"
                                type="number"
                                step="0.0001"
                                numeric
                                placeholder="0.0000"
                                class="min-w-20"
                                @keydown.enter.prevent="addLineFromKeyboard(index)"
                            />
                        </template>

                        <!-- Read-only: what the customer's own target implies, so an unrealistic
                             ask is visible on the line rather than after the cost sheet. -->
                        <template #cell:value="{ line }">
                            <div class="px-1.5 py-1 text-right text-sm tnum" :class="lineValue(line) ? 'text-ink-800' : 'text-ink-300'">
                                {{ lineValue(line) ? money(lineValue(line), customerCurrency) : '—' }}
                            </div>
                        </template>

                        <template #footer>
                            <tr>
                                <td colspan="4" class="px-1.5 py-2 text-right text-xs text-ink-600">Total</td>
                                <td class="px-1.5 py-2 text-right text-sm font-semibold tnum text-ink-900">
                                    {{ pcs(totalQty) }}
                                </td>
                                <td class="px-1.5 py-2" />
                                <td class="px-1.5 py-2 text-right text-sm font-semibold tnum text-ink-900">
                                    {{ totalValue ? money(totalValue, customerCurrency) : '—' }}
                                </td>
                                <td />
                            </tr>
                        </template>
                    </LineItemsTable>

                    <p class="mt-2 text-xs text-ink-500">Enter in the last cell starts the next line.</p>

                    <p v-if="droppedProducts" role="status" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        {{ droppedProducts }} {{ droppedProducts === 1 ? 'line named a product' : 'lines named products' }}
                        belonging to the previous customer. {{ droppedProducts === 1 ? 'It has' : 'They have' }} been cleared.
                    </p>
                </div>
            </Card>

            <template #rail>
                <Card title="Summary">
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Customer</dt>
                            <dd class="min-w-0 truncate text-right" :class="selectedCustomer ? 'text-ink-900' : 'text-ink-400'">
                                {{ selectedCustomer?.name ?? 'Not chosen' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Required by</dt>
                            <dd class="text-right" :class="form.required_by ? 'text-ink-900' : 'text-ink-400'">
                                {{ form.required_by ? date(form.required_by) : 'Open' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-2.5">
                            <dt class="text-xs text-ink-500">Lines</dt>
                            <dd class="tnum text-ink-900">{{ filledLines }}</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Total quantity</dt>
                            <dd class="tnum text-ink-900">{{ pcs(totalQty) }} pcs</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-2.5">
                            <dt class="text-xs text-ink-500">Indicative value</dt>
                            <dd class="text-base font-semibold tnum text-ink-900">
                                {{ totalValue ? money(totalValue, customerCurrency) : '—' }}
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-xs leading-relaxed text-ink-500">
                        The customer's own target rate × quantity ÷ 1000 — what they hope to pay,
                        not a quoted price. The cost sheet decides that.
                    </p>
                </Card>

                <Card title="Notes">
                    <FormField label="Inquiry notes" :error="form.errors.notes">
                        <textarea
                            v-model="form.notes"
                            rows="6"
                            class="form-textarea"
                            placeholder="Anything the merchandiser needs to remember."
                        />
                    </FormField>
                </Card>
            </template>

            <template #footer>
                <FormFooter
                    :form="form"
                    cancel-href="/inquiries"
                    :summary="`${filledLines} ${filledLines === 1 ? 'line' : 'lines'} · ${pcs(totalQty)} pcs`"
                    :label="isEdit ? 'Save changes' : 'Save draft'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
