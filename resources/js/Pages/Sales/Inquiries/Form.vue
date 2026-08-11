<script setup>
import { computed } from 'vue';
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
import { money, pcs } from '@/plugins/formatting';

const props = defineProps({
    inquiry: { type: Object, default: null },
    customers: { type: Array, default: () => [] },
    productTypes: { type: Array, default: () => [] },
    sources: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.inquiry));

const form = useForm({
    customer_id: props.inquiry?.customer_id ?? '',
    inquiry_date: props.inquiry?.inquiry_date ?? new Date().toISOString().slice(0, 10),
    required_by: props.inquiry?.required_by ?? '',
    source: props.inquiry?.source ?? '',
    notes: props.inquiry?.notes ?? '',
    lines: props.inquiry?.lines?.length
        ? props.inquiry.lines.map((line) => ({ ...line }))
        : [blankLine()],
});

function blankLine() {
    return { product_id: '', description: '', product_type: '', qty: '', target_rate_per_m: '', notes: '' };
}

function addLine() {
    form.lines = [...form.lines, blankLine()];
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

function submit() {
    isEdit.value
        ? form.put(`/inquiries/${props.inquiry.id}`)
        : form.post('/inquiries');
}

const columns = [
    { key: 'description', label: 'Description' },
    { key: 'product_type', label: 'Product type', width: '13rem' },
    { key: 'qty', label: 'Quantity (pcs)', width: '10rem', align: 'right' },
    { key: 'target_rate_per_m', label: 'Target rate /M', width: '10rem', align: 'right' },
    { key: 'value', label: 'Indicative value', width: '11rem', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit inquiry' : 'New inquiry'" />

        <template #title>{{ isEdit ? `Inquiry ${inquiry.number ?? '(unnumbered)'}` : 'New inquiry' }}</template>
        <template #subtitle>
            A draft carries no number — one is assigned when it is submitted (BR-34).
        </template>

        <FormLayout @submit="submit">
                <Card title="Customer and dates">
                    <div class="grid gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
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

                        <FormField label="Inquiry date" :error="form.errors.inquiry_date" required>
                            <DateInput v-model="form.inquiry_date" />
                        </FormField>

                        <FormField
                            label="Required by"
                            rule="BR-29"
                            hint="Feeds the promised date once this becomes an order."
                            :error="form.errors.required_by"
                        >
                            <DateInput v-model="form.required_by" />
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
                                <template #cell:description="{ line }">
                                <TextInput cell v-model="line.description" placeholder="Centre-fold satin care label, 40 × 20 mm" />
                            </template>

                                <template #cell:product_type="{ line }">
                                <SelectInput v-model="line.product_type" :options="productTypes" />
                            </template>

                                <template #cell:qty="{ line }">
                                <TextInput cell v-model="line.qty" type="number" numeric min="1" placeholder="0" />
                            </template>

                                <template #cell:target_rate_per_m="{ line }">
                                <!-- BR-1: everything in this business is priced per 1000 pieces. -->
                                <TextInput cell v-model="line.target_rate_per_m" type="number" step="0.0001" numeric placeholder="0.0000" />
                            </template>

                            <!-- Read-only: what the customer's own target implies, so an unrealistic
                                 ask is visible on the line rather than after the cost sheet. -->
                                <template #cell:value="{ line }">
                                <div class="px-1.5 py-1 text-right text-sm tnum" :class="lineValue(line) ? 'text-ink-800' : 'text-ink-300'">
                                    {{ lineValue(line) ? money(lineValue(line)) : '—' }}
                                </div>
                            </template>

                                <template #footer>
                                <tr>
                                    <td colspan="3" class="px-1.5 py-2 text-right text-xs text-ink-600">Total</td>
                                    <td class="px-1.5 py-2 text-right text-sm font-semibold tnum text-ink-900">
                                        {{ pcs(totalQty) }}
                                    </td>
                                    <td class="px-1.5 py-2" />
                                    <td class="px-1.5 py-2 text-right text-sm font-semibold tnum text-ink-900">
                                        {{ totalValue ? money(totalValue) : '—' }}
                                    </td>
                                    <td />
                                </tr>
                            </template>
                        </LineItemsTable>

                        <p v-if="form.errors.lines" class="mt-2 text-xs text-rose-600">{{ form.errors.lines }}</p>
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
                                {{ form.required_by || 'Open' }}
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
                                {{ totalValue ? money(totalValue) : '—' }}
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-[11px] leading-relaxed text-ink-500">
                        The customer's own target rate × quantity ÷ 1000 (BR-1) — what they hope to pay,
                        not a quoted price. The cost sheet decides that.
                    </p>
                </Card>

                <Card title="Notes">
                    <FormField :error="form.errors.notes">
                        <textarea
                            v-model="form.notes"
                            rows="8"
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
