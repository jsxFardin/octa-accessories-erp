<script setup>
import { computed, ref, watch } from 'vue';
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
import { date, isoDate, pcs, todayIso } from '@/plugins/formatting';

const props = defineProps({
    list: { type: Object, default: null },
    /** The customer whose page this list was started from, resolved server-side. */
    preselectCustomerId: { type: Number, default: null },
    customers: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.list));

function blankLine() {
    return { product_id: "", description: "", min_qty: 0, rate_per_m: "" };
}

const customerCurrency = (customerId) => props.customers.find((c) => String(c.id) === String(customerId))?.currency_id ?? null;

const form = useForm({
    code: props.list?.code ?? "",
    name: props.list?.name ?? "",
    customer_id: props.list?.customer_id ?? props.preselectCustomerId ?? "",
    // The customer's trading currency, not the factory's: an agreed rate is in the money the
    // customer pays in, and most of these customers are quoted in USD.
    currency_id: props.list?.currency_id
        ?? customerCurrency(props.preselectCustomerId)
        ?? props.currencies.find((c) => c.is_base)?.id
        ?? "",
    valid_from: isoDate(props.list?.valid_from) || todayIso(),
    valid_to: isoDate(props.list?.valid_to),
    // The row comes straight from the table, where this is 1 or 0; a checkbox wants a boolean.
    is_active: Boolean(props.list?.is_active ?? true),
    lines: props.list?.lines?.length ? props.list.lines.map((line) => ({ ...line })) : [blankLine()],
});

/** A price list belongs to one customer, so only that customer's products can be priced. */
const availableProducts = computed(() =>
    form.customer_id
        ? props.products.filter((product) => product.customer_id === Number(form.customer_id))
        : props.products,
);

function addLine() {
    form.lines = [...form.lines, blankLine()];
}

/** A row nobody typed into: the blank one a new form starts with, or a stray "Add break". */
function isBlank(line) {
    return !line.product_id && !line.rate_per_m && !String(line.description ?? '').trim();
}

/*
 * A change of customer leaves the previous customer's products behind, and takes the
 * currency with it when the user had not chosen one deliberately for the old customer.
 */
const droppedProducts = ref(0);

watch(() => form.customer_id, (customer, previous) => {
    droppedProducts.value = 0;

    if (!customer) return;

    if (!form.currency_id || form.currency_id === customerCurrency(previous)) {
        form.currency_id = customerCurrency(customer) ?? form.currency_id;
    }

    form.lines.forEach((line) => {
        const product = props.products.find((row) => row.id === Number(line.product_id));

        if (product && product.customer_id !== Number(customer)) {
            line.product_id = "";
            droppedProducts.value += 1;
        }
    });
});

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
}

/** Two breaks at the same floor for one product make the applicable rate ambiguous. */
const duplicateBreak = computed(() => {
    const seen = new Set();

    return form.lines.some((line) => {
        if (!line.product_id) return false;

        const key = `${line.product_id}:${Number(line.min_qty) || 0}`;

        if (seen.has(key)) return true;

        seen.add(key);

        return false;
    });
});

function submit() {
    // Blank rows are dropped rather than failing the save with "line 2 product is required".
    form.transform((data) => ({ ...data, lines: data.lines.filter((line) => !isBlank(line)) }));

    isEdit.value ? form.put(`/price-lists/${props.list.id}`) : form.post("/price-lists");
}

const filledLines = computed(() => form.lines.filter((line) => !isBlank(line)).length);

const columns = [
    { key: "product_id", label: "Product", width: "16rem" },
    { key: "min_qty", label: "From quantity (pcs)", width: "10rem", align: "right" },
    { key: "rate_per_m", label: "Rate per 1,000 pcs", width: "10rem", align: "right" },
    { key: "description", label: "Note" },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit price list' : 'New price list'" />

        <template #title>{{ isEdit ? `Price list ${list.code}` : "New price list" }}</template>
        <template #subtitle>Agreed rates by quantity break, for one customer</template>

        <FormLayout @submit="submit">

            <Card title="Price list">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <FormField label="Code" :error="form.errors.code" required>
                        <TextInput v-model="form.code" placeholder="PL-NFJ-2026" />
                    </FormField>

                    <FormField label="Name" :error="form.errors.name" required>
                        <TextInput v-model="form.name" />
                    </FormField>

                    <FormField label="Customer" :error="form.errors.customer_id" required>
                        <SelectInput v-model="form.customer_id" placeholder="— select —" :options="customers" value-key="id" label-key="name" />
                    </FormField>

                    <FormField label="Currency" :error="form.errors.currency_id" required>
                        <SelectInput v-model="form.currency_id" :placeholder="null" :options="currencies" value-key="id" label-key="code" />
                    </FormField>

                    <FormField label="Valid from" :error="form.errors.valid_from" required>
                        <DateInput v-model="form.valid_from" />
                    </FormField>

                    <FormField label="Valid to" hint="Leave empty for open-ended." :error="form.errors.valid_to">
                        <DateInput v-model="form.valid_to" />
                    </FormField>
                </div>

                <!-- The form always carried this value and never showed it. -->
                <label class="mt-3 flex items-start gap-2 text-sm text-ink-700">
                    <input v-model="form.is_active" type="checkbox" class="form-checkbox mt-0.5" data-active>
                    <span>
                        <span class="font-medium text-ink-900">Active</span>
                        <span class="block text-xs text-ink-600">
                            New quotations read rates only from active lists. Untick to stop using this list without deleting it.
                        </span>
                    </span>
                </label>
            </Card>

            <Card title="Rates" subtitle="One row per quantity break. The break with the highest starting quantity at or below the ordered quantity applies." :padded="false">
                <div class="p-3">
                    <LineItemsTable
                        :columns="columns"
                        :lines="form.lines"
                        :errors="form.errors"
                        add-label="Add break"
                        empty="A price list needs at least one rate."
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
                                hint-key="name"
                            />
                        </template>

                        <template #cell:min_qty="{ line }">
                            <TextInput cell v-model="line.min_qty" type="number" numeric min="0" />
                        </template>

                        <template #cell:rate_per_m="{ line }">
                            <TextInput cell v-model="line.rate_per_m" type="number" step="0.0001" numeric />
                        </template>

                        <template #cell:description="{ line }">
                            <TextInput cell v-model="line.description" placeholder="Optional note" />
                        </template>
                    </LineItemsTable>

                    <p v-if="duplicateBreak" class="mt-2 text-xs text-rose-600">
                        Two breaks for the same product start at the same quantity — the applicable rate
                        would be ambiguous.
                    </p>
                    <p v-if="droppedProducts" role="status" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        {{ droppedProducts }} {{ droppedProducts === 1 ? 'rate named a product' : 'rates named products' }}
                        belonging to the previous customer. {{ droppedProducts === 1 ? 'It has' : 'They have' }} been cleared.
                    </p>
                    <p v-if="form.errors.lines" class="mt-2 text-xs text-rose-600">{{ form.errors.lines }}</p>
                </div>
            </Card>

            <template #rail>
                <Card title="Price list">
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Breaks</dt>
                            <dd class="tnum text-ink-900">{{ filledLines }}</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Valid from</dt>
                            <dd class="text-right" :class="form.valid_from ? 'text-ink-900' : 'text-ink-400'">
                                {{ form.valid_from ? date(form.valid_from) : 'Not set' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Valid to</dt>
                            <dd class="text-right" :class="form.valid_to ? 'text-ink-900' : 'text-ink-400'">
                                {{ form.valid_to ? date(form.valid_to) : 'Open-ended' }}
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="duplicateBreak"
                        class="mt-3 rounded bg-amber-50 px-2 py-1.5 text-xs leading-relaxed text-amber-900"
                    >
                        Two breaks share a product and a starting quantity, so no one rate applies. Move one of them.
                    </p>
                    <p v-else class="mt-3 text-xs leading-relaxed text-ink-500">
                        The break with the highest starting quantity at or below the ordered quantity applies:
                        a 10,000 break prices a 25,000-piece order unless a higher break exists.
                    </p>
                </Card>
            </template>

            <template #footer>
                <FormFooter
                    :form="form"
                    :disabled="duplicateBreak"
                    :disabled-reason="duplicateBreak ? 'Two lines give the same product the same starting quantity. Change or remove one.' : null"
                    cancel-href="/price-lists"
                    :label="isEdit ? 'Save changes' : 'Create price list'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
