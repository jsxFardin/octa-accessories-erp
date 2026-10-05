<script setup>
import { computed, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import LineItemsTable from '@/Components/Ui/LineItemsTable.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { todayIso } from '@/plugins/formatting';

const props = defineProps({
    customers: { type: Array, default: () => [] },
    lots: { type: Array, default: () => [] },
    labTests: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    /** `{ [lab_test_id]: { pass_value, from_customer, is_mandatory } }` for the chosen customer and product. */
    thresholds: { type: Object, default: () => ({}) },
});

const form = useForm({
    lot_id: null,
    product_id: null,
    customer_id: null,
    tested_on: todayIso(),
    remarks: '',
    // One row per test in the catalogue. A row left blank is a test that was not run.
    results: props.labTests.map((test) => ({ lab_test_id: test.id, result_value: '' })),
});

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name, hint: c.code })));
const lotOptions = computed(() => props.lots.map((l) => ({ value: l.id, label: l.lot_no })));
const productOptions = computed(() => props.products.map((p) => ({ value: p.id, label: p.code, hint: p.name })));

const testById = computed(() => Object.fromEntries(props.labTests.map((test) => [test.id, test])));

/** The scales the table itself allows, in words, with what to type. */
const SCALES = {
    grey_1_5: { label: 'Grey scale', placeholder: '1 to 5', rule: 'or higher' },
    percent: { label: 'Percentage', placeholder: '0 to 100', rule: 'or lower' },
    delta_e: { label: 'Colour difference (ΔE)', placeholder: 'e.g. 0.8', rule: 'or lower' },
    pass_fail: { label: 'Pass or fail', placeholder: '', rule: '' },
    numeric: { label: 'Number', placeholder: 'value', rule: 'or higher' },
};

const scaleOf = (line) => SCALES[testById.value[line.lab_test_id]?.scale] ?? SCALES.numeric;
const isPassFail = (line) => testById.value[line.lab_test_id]?.scale === 'pass_fail';
const thresholdOf = (line) => props.thresholds[line.lab_test_id] ?? null;

const PASS_FAIL = [{ value: 'pass', label: 'Pass' }, { value: 'fail', label: 'Fail' }];

/*
 * The threshold shown is the one the report will be judged against. It used to stay at the
 * house default whoever the customer was. Only the thresholds are fetched; nothing typed is lost.
 */
watch(() => [form.customer_id, form.product_id], ([customerId, productId]) => {
    router.reload({
        only: ['thresholds'],
        data: { customer_id: customerId ?? undefined, product_id: productId ?? undefined },
    });
});

const entered = computed(() => form.results.filter((line) => String(line.result_value ?? '').trim() !== '').length);

/** Tests the customer insists on that have no result yet. Said, not enforced: the report can still be saved. */
const missingRequired = computed(() => form.results
    .filter((line) => thresholdOf(line)?.is_mandatory && String(line.result_value ?? '').trim() === '')
    .map((line) => testById.value[line.lab_test_id]?.code));

const blockedBy = computed(() => (entered.value === 0 ? 'Enter the result of at least one test.' : null));

const columns = [
    { key: 'test', label: 'Test', errorKeys: ['lab_test_id'] },
    { key: 'scale', label: 'Measured as', width: '12rem', errorKeys: [] },
    { key: 'threshold', label: 'Must be', width: '12rem', errorKeys: [] },
    { key: 'result_value', label: 'Result', width: '11rem' },
];

function submit() {
    form.post('/lab/reports', { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head title="New test report" />

        <template #title>New test report</template>
        <template #subtitle>Enter the result of each test that was run. Pass or fail is worked out when the report is saved.</template>

        <FormLayout @submit="submit">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <FormField label="Customer" :error="form.errors.customer_id" hint="Sets the pass values the customer asks for.">
                    <SelectInput v-model="form.customer_id" :options="customerOptions" hint-key="hint" placeholder="Optional…" clearable />
                </FormField>
                <FormField label="Lot" :error="form.errors.lot_id">
                    <SelectInput v-model="form.lot_id" :options="lotOptions" placeholder="Optional…" clearable />
                </FormField>
                <FormField label="Product" :error="form.errors.product_id">
                    <SelectInput v-model="form.product_id" :options="productOptions" hint-key="hint" placeholder="Optional…" clearable />
                </FormField>
                <FormField label="Tested on" :error="form.errors.tested_on" required>
                    <DateInput v-model="form.tested_on" />
                </FormField>
            </div>

            <Card
                title="Test results"
                rule="BR-32"
                :subtitle="`${entered} of ${form.results.length} tests entered. Leave a test blank if it was not run.`"
                :padded="false"
                class="mt-4"
            >
                <div class="p-3">
                    <LineItemsTable
                        :columns="columns"
                        :lines="form.results"
                        :errors="form.errors"
                        error-prefix="results"
                        fixed
                    >
                        <template #cell:test="{ line }">
                            <p class="pt-1.5 text-sm">
                                <span class="font-medium text-ink-900">{{ testById[line.lab_test_id]?.code }}</span>
                                <span class="ml-1.5 text-ink-700">{{ testById[line.lab_test_id]?.name }}</span>
                            </p>
                            <p v-if="thresholdOf(line)?.is_mandatory" class="text-xs font-medium text-amber-800" data-required-test>
                                The customer requires this test
                            </p>
                        </template>

                        <template #cell:scale="{ line }">
                            <p class="pt-1.5 text-sm text-ink-700">
                                {{ scaleOf(line).label }}
                                <span v-if="testById[line.lab_test_id]?.unit" class="text-ink-600">({{ testById[line.lab_test_id].unit }})</span>
                            </p>
                        </template>

                        <template #cell:threshold="{ line }">
                            <p class="pt-1.5 text-sm tnum" data-threshold>
                                <template v-if="thresholdOf(line)?.pass_value != null">
                                    <span class="font-medium text-ink-900">{{ isPassFail(line) ? 'Pass' : thresholdOf(line).pass_value }}</span>
                                    <span class="ml-1 text-ink-700">{{ scaleOf(line).rule }}</span>
                                </template>
                                <span v-else class="text-ink-600">No pass value set</span>
                            </p>
                            <p v-if="thresholdOf(line)?.from_customer" class="text-xs text-brand-700">Customer's own figure</p>
                        </template>

                        <template #cell:result_value="{ line }">
                            <SelectInput
                                v-if="isPassFail(line)"
                                v-model="line.result_value"
                                :options="PASS_FAIL"
                                placeholder="Not run"
                                clearable
                            />
                            <TextInput v-else v-model="line.result_value" inputmode="decimal" :placeholder="scaleOf(line).placeholder" />
                        </template>
                    </LineItemsTable>

                    <p v-if="form.errors.results" class="mt-2 text-sm text-rose-700" role="alert">{{ form.errors.results }}</p>

                    <p v-if="missingRequired.length && entered > 0" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900" data-missing-required>
                        This customer requires {{ missingRequired.join(', ') }}, which {{ missingRequired.length === 1 ? 'has' : 'have' }} no result yet.
                        The report can be saved without {{ missingRequired.length === 1 ? 'it' : 'them' }}.
                    </p>
                </div>
            </Card>

            <FormField label="Remarks" :error="form.errors.remarks" class="mt-4">
                <textarea v-model="form.remarks" rows="2" class="form-textarea" />
            </FormField>

            <template #footer>
                <FormFooter
                    :form="form"
                    :disabled="blockedBy !== null"
                    :disabled-reason="blockedBy"
                    cancel-href="/lab"
                    label="Save report"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
