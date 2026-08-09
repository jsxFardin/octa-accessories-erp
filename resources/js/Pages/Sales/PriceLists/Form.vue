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
import UnsavedBar from '@/Components/Ui/UnsavedBar.vue';
import { pcs } from '@/plugins/formatting';

const props = defineProps({
    list: { type: Object, default: null },
    customers: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.list));

function blankLine() {
    return { product_id: "", description: "", min_qty: 0, rate_per_m: "" };
}

const form = useForm({
    code: props.list?.code ?? "",
    name: props.list?.name ?? "",
    customer_id: props.list?.customer_id ?? "",
    currency_id: props.list?.currency_id ?? props.currencies.find((c) => c.is_base)?.id ?? "",
    valid_from: props.list?.valid_from ?? new Date().toISOString().slice(0, 10),
    valid_to: props.list?.valid_to ?? "",
    is_active: props.list?.is_active ?? true,
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
    isEdit.value ? form.put(`/price-lists/${props.list.id}`) : form.post("/price-lists");
}

const columns = [
    { key: "product_id", label: "Product", width: "18rem" },
    { key: "min_qty", label: "From quantity", width: "11rem", align: "right" },
    { key: "rate_per_m", label: "Rate / 1,000", width: "10rem", align: "right" },
    { key: "description", label: "Note" },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit price list' : 'New price list'" />

        <template #title>{{ isEdit ? `Price list ${list.code}` : "New price list" }}</template>
        <template #subtitle>Agreed rates by quantity break, for one customer</template>

        <template #actions>
            <Button href="/price-lists">Cancel</Button>
            <Button variant="primary" :loading="form.processing" :disabled="duplicateBreak" @click="submit">
                {{ isEdit ? "Save changes" : "Create price list" }}
            </Button>
        </template>

        <div class="max-w-6xl space-y-4">
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
            </Card>

            <Card title="Rates" subtitle="One row per quantity break; the highest floor at or below the ordered quantity wins" :padded="false">
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
                            <TextInput v-model="line.min_qty" type="number" numeric min="0" />
                        </template>

                        <template #cell:rate_per_m="{ line }">
                            <TextInput v-model="line.rate_per_m" type="number" step="0.0001" numeric />
                        </template>

                        <template #cell:description="{ line }">
                            <TextInput v-model="line.description" placeholder="Optional note" />
                        </template>
                    </LineItemsTable>

                    <p v-if="duplicateBreak" class="mt-2 text-xs text-rose-600">
                        Two breaks for the same product start at the same quantity — the applicable rate
                        would be ambiguous.
                    </p>
                    <p v-if="form.errors.lines" class="mt-2 text-xs text-rose-600">{{ form.errors.lines }}</p>
                </div>
            </Card>
        </div>

        <UnsavedBar :form="form" @save="submit" />
    </AppLayout>
</template>
