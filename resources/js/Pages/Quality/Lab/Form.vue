<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    customers: { type: Array, default: () => [] },
    lots: { type: Array, default: () => [] },
    labTests: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
});

const form = useForm({
    lot_id: null,
    product_id: null,
    customer_id: null,
    tested_on: new Date().toISOString().slice(0, 10),
    remarks: '',
    results: props.labTests.map((t) => ({
        lab_test_id: t.id,
        result_value: '',
        _code: t.code,
        _name: t.name,
        _scale: t.scale,
        _unit: t.unit,
        _pass: t.default_pass_value,
    })),
});

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: `${c.code} — ${c.name}` })));
const lotOptions = computed(() => props.lots.map((l) => ({ value: l.id, label: l.lot_no })));
const productOptions = computed(() => props.products.map((p) => ({ value: p.id, label: `${p.code} — ${p.name}` })));

function scalePlaceholder(scale) {
    return { grey: '1–5', percentage: '0–100%', delta_e: 'ΔE', pass_fail: 'pass / fail' }[scale] || 'value';
}

function submit() {
    form.post('/lab/reports', { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head title="New test report" />

        <template #title>New test report</template>
        <template #subtitle>QL-5 — enter results per test; pass/fail is computed automatically</template>

        <FormLayout @submit="submit">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Customer" :error="form.errors.customer_id">
                        <SelectInput v-model="form.customer_id" :options="customerOptions" placeholder="Optional…" clearable />
                    </FormField>
                    <FormField label="Lot" :error="form.errors.lot_id">
                        <SelectInput v-model="form.lot_id" :options="lotOptions" placeholder="Optional…" clearable />
                    </FormField>
                    <FormField label="Product" :error="form.errors.product_id">
                        <SelectInput v-model="form.product_id" :options="productOptions" placeholder="Optional…" clearable />
                    </FormField>
                    <FormField label="Tested on" :error="form.errors.tested_on" required>
                        <DateInput v-model="form.tested_on" />
                    </FormField>
                </div>

                <Card title="Test results" rule="BR-32" :padded="false" class="mt-4">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-ink-500">
                                <tr>
                                    <th class="px-3 py-2">Code</th>
                                    <th class="px-3 py-2">Test</th>
                                    <th class="px-3 py-2">Scale</th>
                                    <th class="px-3 py-2 text-right">Threshold</th>
                                    <th class="px-3 py-2 w-40">Result</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(line, index) in form.results" :key="line.lab_test_id">
                                    <td class="px-3 py-1.5 font-medium">{{ line._code }}</td>
                                    <td class="px-3 py-1.5">{{ line._name }}</td>
                                    <td class="px-3 py-1.5 text-ink-500">{{ line._scale }} <span v-if="line._unit" class="text-xs">({{ line._unit }})</span></td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ line._pass ?? '—' }}</td>
                                    <td class="px-3 py-1.5">
                                        <input
                                            v-model="line.result_value"
                                            type="text"
                                            class="w-full rounded border-slate-300 text-sm"
                                            :placeholder="scalePlaceholder(line._scale)"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <FormField label="Remarks" :error="form.errors.remarks" class="mt-4">
                    <textarea v-model="form.remarks" rows="2" class="w-full rounded-md border-slate-300 text-sm" />
                </FormField>

            <template #footer>
                <FormFooter
                    :form="form"
                    cancel-href="/lab"
                    label="Save report"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
