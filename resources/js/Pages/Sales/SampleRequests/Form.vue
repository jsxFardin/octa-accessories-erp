<script setup>
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import LineItemsTable from '@/Components/Ui/LineItemsTable.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { isoDate, pcs, titleCase, todayIso } from '@/plugins/formatting';

const props = defineProps({
    sampleRequest: { type: Object, default: null },
    /** Resolved server-side from `?inquiry=`, `?sales_order=` or `?customer=`. */
    preselect: { type: Object, default: () => ({}) },
    customers: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    inquiries: { type: Array, default: () => [] },
    salesOrders: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    artworkVersions: { type: Array, default: () => [] },
    specs: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.sampleRequest));

const form = useForm({
    customer_id: props.sampleRequest?.customer_id ?? props.preselect?.customer_id ?? '',
    inquiry_id: props.sampleRequest?.inquiry_id ?? props.preselect?.inquiry_id ?? '',
    sales_order_id: props.sampleRequest?.sales_order_id ?? props.preselect?.sales_order_id ?? '',
    sample_type: props.sampleRequest?.sample_type ?? '',
    requested_on: isoDate(props.sampleRequest?.requested_on) || todayIso(),
    required_by: isoDate(props.sampleRequest?.required_by),
    is_chargeable: props.sampleRequest?.is_chargeable ?? false,
    charge_amount: props.sampleRequest?.charge_amount ? String(Number(props.sampleRequest.charge_amount)) : '',
    notes: props.sampleRequest?.notes ?? '',
    lines: props.sampleRequest?.lines?.length
        ? props.sampleRequest.lines.map((line) => ({ ...line, qty: String(Number(line.qty)) }))
        : [blankLine()],
});

function blankLine() {
    return { product_id: '', product_spec_id: '', artwork_version_id: '', description: '', qty: '', colourway: '' };
}

const addLine = () => { form.lines = [...form.lines, blankLine()]; };
const removeLine = (index) => { form.lines = form.lines.filter((_, i) => i !== index); };

const typeOptions = computed(() => props.types.map((t) => ({ value: t, label: titleCase(t) })));

const forCustomer = (rows) => (form.customer_id ? rows.filter((row) => row.customer_id === Number(form.customer_id)) : []);
const availableInquiries = computed(() => forCustomer(props.inquiries));
const availableOrders = computed(() => forCustomer(props.salesOrders));
const availableProducts = computed(() => (form.customer_id
    ? props.products.filter((p) => p.customer_id === null || p.customer_id === Number(form.customer_id))
    : props.products));

const versionsFor = (line) => (line.product_id
    ? props.artworkVersions.filter((v) => v.product_id === Number(line.product_id)).map((v) => ({ ...v, label: `v${v.version_no} · ${titleCase(v.status)}` }))
    : []);
const specsFor = (line) => (line.product_id
    ? props.specs.filter((s) => s.product_id === Number(line.product_id)).map((s) => ({ ...s, label: `Spec v${s.version_no} · ${titleCase(s.status)}` }))
    : []);

/** Choosing a product describes the line and picks its only, or current, spec and version. */
function onProductChange(line) {
    const product = props.products.find((p) => p.id === Number(line.product_id));

    line.product_spec_id = '';
    line.artwork_version_id = '';

    if (!product) return;

    if (!String(line.description ?? '').trim()) line.description = product.name;

    const specs = specsFor(line);
    const current = specs.find((s) => s.status === 'current') ?? (specs.length === 1 ? specs[0] : null);
    if (current) line.product_spec_id = current.id;

    const versions = versionsFor(line);
    if (versions.length === 1) line.artwork_version_id = versions[0].id;
}

watch(() => form.customer_id, (customer) => {
    if (!props.inquiries.some((i) => String(i.id) === String(form.inquiry_id) && i.customer_id === Number(customer))) form.inquiry_id = '';
    if (!props.salesOrders.some((o) => String(o.id) === String(form.sales_order_id) && o.customer_id === Number(customer))) form.sales_order_id = '';

    form.lines.forEach((line) => {
        const product = props.products.find((p) => p.id === Number(line.product_id));

        if (product && product.customer_id !== null && product.customer_id !== Number(customer)) {
            line.product_id = '';
            line.product_spec_id = '';
            line.artwork_version_id = '';
        }
    });
});

watch(() => form.is_chargeable, (chargeable) => { if (!chargeable) form.charge_amount = ''; });

const isBlank = (line) => !line.product_id && !String(line.description ?? '').trim() && !line.qty;
const filledLines = computed(() => form.lines.filter((line) => !isBlank(line)).length);
const totalQty = computed(() => form.lines.reduce((sum, line) => sum + (Number(line.qty) || 0), 0));

function submit() {
    form.transform((data) => ({ ...data, lines: data.lines.filter((line) => !isBlank(line)) }));

    isEdit.value
        ? form.put(`/sample-requests/${props.sampleRequest.id}`)
        : form.post('/sample-requests');
}

const columns = [
    { key: 'product_id', label: 'Product', width: '12rem' },
    { key: 'description', label: 'Description', required: true },
    { key: 'artwork_version_id', label: 'Artwork version', width: '11rem' },
    { key: 'product_spec_id', label: 'Spec', width: '10rem' },
    { key: 'colourway', label: 'Colourway', width: '9rem' },
    { key: 'qty', label: 'Qty (pcs)', width: '7rem', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit sample request' : 'New sample request'" />

        <template #title>{{ isEdit ? `Sample request ${sampleRequest.number}` : 'New sample request' }}</template>
        <template #subtitle>What the sample room should make, for whom, and by when. The request is numbered as soon as it is raised.</template>

        <FormLayout @submit="submit">
            <Card title="Who it is for">
                <div class="grid gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                    <FormField label="Customer" :error="form.errors.customer_id" required>
                        <SelectInput v-model="form.customer_id" placeholder="— select —" :options="customers" value-key="id" label-key="name" hint-key="code" />
                    </FormField>

                    <FormField label="Sample type" :error="form.errors.sample_type" required hint="Pre-production approval is what releases bulk for a customer who requires it.">
                        <SelectInput v-model="form.sample_type" placeholder="— select —" :options="typeOptions" />
                    </FormField>

                    <FormField label="Inquiry" :error="form.errors.inquiry_id" :hint="form.customer_id && !availableInquiries.length ? 'No open inquiry on this customer.' : null">
                        <SelectInput v-model="form.inquiry_id" placeholder="— none —" :options="availableInquiries" value-key="id" label-key="number" :disabled="!form.customer_id || !availableInquiries.length" />
                    </FormField>

                    <FormField label="Sales order" :error="form.errors.sales_order_id" :hint="form.customer_id && !availableOrders.length ? 'No open order on this customer.' : null">
                        <SelectInput v-model="form.sales_order_id" placeholder="— none —" :options="availableOrders" value-key="id" label-key="number" :disabled="!form.customer_id || !availableOrders.length" />
                    </FormField>

                    <FormField label="Requested on" :error="form.errors.requested_on" required>
                        <DateInput v-model="form.requested_on" />
                    </FormField>

                    <FormField label="Required by" :error="form.errors.required_by">
                        <DateInput v-model="form.required_by" :min="form.requested_on" />
                    </FormField>
                </div>
            </Card>

            <Card title="Charge" subtitle="Most samples are free. A charged one is invoiced later; capture the amount now so it is not forgotten.">
                <div class="grid gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                    <FormField label="Chargeable" :error="form.errors.is_chargeable">
                        <label class="flex items-center gap-2 text-sm text-ink-800">
                            <input v-model="form.is_chargeable" type="checkbox" class="form-checkbox" />
                            The customer pays for these samples
                        </label>
                    </FormField>
                    <FormField v-if="form.is_chargeable" label="Charge amount" :error="form.errors.charge_amount" required>
                        <TextInput v-model="form.charge_amount" type="number" step="0.01" min="0" numeric placeholder="0.00" />
                    </FormField>
                </div>
            </Card>

            <Card title="What to make" subtitle="One line per sample. Name the product where one exists so a job card can be raised from the line." :padded="false">
                <template #actions>
                    <span class="text-xs text-ink-500">{{ filledLines }} {{ filledLines === 1 ? 'line' : 'lines' }}</span>
                </template>

                <div class="px-4 py-3">
                    <LineItemsTable :columns="columns" :lines="form.lines" :errors="form.errors" add-label="Add line" empty="No lines yet" empty-hint="What the customer wants to see, in their words." @add="addLine" @remove="removeLine">
                        <template #cell:product_id="{ line }">
                            <SelectInput v-model="line.product_id" class="min-w-36" placeholder="— none yet —" :options="availableProducts" value-key="id" label-key="code" hint-key="name" @update:model-value="onProductChange(line)" />
                        </template>
                        <template #cell:description="{ line }">
                            <TextInput cell v-model="line.description" class="min-w-48" placeholder="Woven main label, navy ground, 2 colours" />
                        </template>
                        <template #cell:artwork_version_id="{ line }">
                            <SelectInput v-model="line.artwork_version_id" placeholder="—" :options="versionsFor(line)" value-key="id" label-key="label" :disabled="!line.product_id" />
                        </template>
                        <template #cell:product_spec_id="{ line }">
                            <SelectInput v-model="line.product_spec_id" placeholder="—" :options="specsFor(line)" value-key="id" label-key="label" :disabled="!line.product_id" />
                        </template>
                        <template #cell:colourway="{ line }">
                            <TextInput cell v-model="line.colourway" class="min-w-24" placeholder="Navy / white" />
                        </template>
                        <template #cell:qty="{ line }">
                            <TextInput cell v-model="line.qty" type="number" numeric min="1" placeholder="0" class="min-w-20" />
                        </template>
                        <template #footer>
                            <tr>
                                <td colspan="5" class="px-1.5 py-2 text-right text-xs text-ink-600">Total</td>
                                <td class="px-1.5 py-2 text-right text-sm font-semibold tnum text-ink-900">{{ pcs(totalQty) }}</td>
                                <td class="px-1.5 py-2" />
                            </tr>
                        </template>
                    </LineItemsTable>
                </div>
            </Card>

            <Card title="Notes">
                <FormField label="Notes for the sample room" :error="form.errors.notes">
                    <textarea v-model="form.notes" rows="3" class="form-textarea" placeholder="Reference the customer's tech pack, the finish wanted, anything the line does not say." />
                </FormField>
            </Card>

            <template #footer>
                <FormFooter :form="form" :label="isEdit ? 'Save changes' : 'Raise request'" @save="submit" :cancel-href="isEdit ? `/sample-requests/${sampleRequest.id}` : '/sample-requests'" :summary="`${filledLines} ${filledLines === 1 ? 'line' : 'lines'} · ${pcs(totalQty)} pcs`" />
            </template>
        </FormLayout>
    </AppLayout>
</template>
