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
    inquiry: { type: Object, default: null },
    customers: { type: Array, default: () => [] },
    productTypes: { type: Array, default: () => [] },
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

function submit() {
    isEdit.value
        ? form.put(`/inquiries/${props.inquiry.id}`)
        : form.post('/inquiries');
}

const columns = [
    { key: 'description', label: 'Description' },
    { key: 'product_type', label: 'Product type', width: '11rem' },
    { key: 'qty', label: 'Quantity (pcs)', width: '9rem', align: 'right' },
    { key: 'target_rate_per_m', label: 'Target rate /M', width: '9rem', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit inquiry' : 'New inquiry'" />

        <template #title>{{ isEdit ? `Inquiry ${inquiry.number ?? '(unnumbered)'}` : 'New inquiry' }}</template>
        <template #subtitle>
            A draft carries no number — one is assigned when it is submitted (BR-34).
        </template>

        <template #actions>
            <Button href="/inquiries">Cancel</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">
                {{ isEdit ? 'Save changes' : 'Save draft' }}
            </Button>
        </template>

        <form class="max-w-5xl space-y-4" @submit.prevent="submit">
            <Card title="Customer and dates">
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

                    <FormField label="Inquiry date" :error="form.errors.inquiry_date" required>
                        <DateInput v-model="form.inquiry_date" />
                    </FormField>

                    <FormField
                        label="Required by"
                        hint="Feeds the promised date once this becomes an order (BR-29)."
                        :error="form.errors.required_by"
                    >
                        <DateInput v-model="form.required_by" />
                    </FormField>

                    <FormField label="Source" :error="form.errors.source">
                        <SelectInput
                            v-model="form.source"
                            :options="[
                                { value: 'email', label: 'Email' },
                                { value: 'phone', label: 'Phone' },
                                { value: 'visit', label: 'Visit' },
                                { value: 'buying_house', label: 'Buying house' },
                                { value: 'repeat', label: 'Repeat order' },
                            ]"
                        />
                    </FormField>
                </div>
            </Card>

            <Card
                title="What they are asking for"
                subtitle="One line per label or tag. A quantity is enough to start; the spec comes later."
                :padded="false"
            >
                <div class="p-3">
                    <LineItemsTable
                        :columns="columns"
                        :lines="form.lines"
                        :errors="form.errors"
                        add-label="Add line"
                        empty="Add at least one line — an inquiry cannot be submitted empty."
                        @add="addLine"
                        @remove="removeLine"
                    >
                        <template #cell:description="{ line }">
                            <TextInput v-model="line.description" placeholder="Centre-fold satin care label, 40 × 20 mm" />
                        </template>

                        <template #cell:product_type="{ line }">
                            <SelectInput v-model="line.product_type" :options="productTypes" />
                        </template>

                        <template #cell:qty="{ line }">
                            <TextInput v-model="line.qty" type="number" numeric min="1" />
                        </template>

                        <template #cell:target_rate_per_m="{ line }">
                            <!-- BR-1: everything in this business is priced per 1000 pieces. -->
                            <TextInput v-model="line.target_rate_per_m" type="number" step="0.0001" numeric />
                        </template>

                        <template #footer>
                            <tr>
                                <td colspan="3" class="px-3 py-2 text-right text-xs text-ink-700">Total quantity</td>
                                <td class="px-2 py-2 text-right text-sm font-semibold tnum text-ink-900">
                                    {{ pcs(totalQty) }}
                                </td>
                                <td colspan="2" />
                            </tr>
                        </template>
                    </LineItemsTable>

                    <p v-if="form.errors.lines" class="mt-2 text-xs text-rose-600">{{ form.errors.lines }}</p>
                </div>
            </Card>

            <Card title="Notes">
                <FormField :error="form.errors.notes">
                    <textarea v-model="form.notes" rows="3" class="form-textarea" placeholder="Anything the merchandiser needs to remember." />
                </FormField>
            </Card>
        </form>
        <UnsavedBar :form="form" @save="submit" />

    </AppLayout>
</template>
