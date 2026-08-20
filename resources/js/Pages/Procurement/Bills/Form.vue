<script setup>
import { ref, computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { todayIso } from '@/plugins/formatting';

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    purchaseOrders: { type: Array, default: () => [] },
    grns: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    prefill: { type: Object, default: null },
});

const emptyLine = () => ({ item_id: null, description: '', qty: null, rate: null, tax_id: null });

const form = useForm({
    supplier_id: props.prefill?.supplier_id ?? null,
    po_id: props.prefill?.po_id ?? null,
    grn_id: props.prefill?.grn_id ?? null,
    bill_no: '',
    bill_date: todayIso(),
    due_date: '',
    currency_id: props.prefill?.currency_id ?? null,
    exchange_rate: 1,
    lines: props.prefill?.lines ?? [emptyLine()],
});

const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.id, label: `${s.code} — ${s.name}` })));
const poOptions = computed(() => props.purchaseOrders.filter((po) => !form.supplier_id || po.supplier_id === form.supplier_id).map((po) => ({ value: po.id, label: po.number })));
const grnOptions = computed(() => props.grns.filter((g) => !form.supplier_id || g.supplier_id === form.supplier_id).map((g) => ({ value: g.id, label: g.number })));
const currencyOptions = computed(() => props.currencies.map((c) => ({ value: c.id, label: `${c.code} — ${c.name}` })));

function lineAmount(line) {
    return line.qty && line.rate ? (Number(line.qty) * Number(line.rate)).toFixed(4) : '0.0000';
}

const subtotal = computed(() => form.lines.reduce((sum, line) => sum + Number(lineAmount(line)), 0));

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    if (form.lines.length > 1) form.lines.splice(index, 1);
}

function submit() {
    form.post('/supplier-bills', { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head title="New supplier bill" />

        <template #title>New supplier bill</template>
        <template #subtitle>Enter the supplier's invoice; link to PO and GRN for three-way match</template>

        <FormLayout @submit="submit">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <FormField label="Supplier" :error="form.errors.supplier_id" required>
                        <SelectInput v-model="form.supplier_id" :options="supplierOptions" placeholder="Choose supplier…" />
                    </FormField>
                    <FormField label="Purchase order" :error="form.errors.po_id">
                        <SelectInput v-model="form.po_id" :options="poOptions" placeholder="Optional…" clearable />
                    </FormField>
                    <FormField label="GRN" :error="form.errors.grn_id">
                        <SelectInput v-model="form.grn_id" :options="grnOptions" placeholder="Optional…" clearable />
                    </FormField>
                    <FormField label="Supplier bill no." :error="form.errors.bill_no" required>
                        <TextInput v-model="form.bill_no" />
                    </FormField>
                    <FormField label="Bill date" :error="form.errors.bill_date" required>
                        <DateInput v-model="form.bill_date" />
                    </FormField>
                    <FormField label="Due date" :error="form.errors.due_date">
                        <DateInput v-model="form.due_date" />
                    </FormField>
                    <FormField label="Currency" :error="form.errors.currency_id" required>
                        <SelectInput v-model="form.currency_id" :options="currencyOptions" placeholder="Choose…" />
                    </FormField>
                    <FormField label="Exchange rate" :error="form.errors.exchange_rate">
                        <TextInput v-model="form.exchange_rate" type="number" min="0" step="any" />
                    </FormField>
                </div>

                <Card title="Lines" :padded="false" class="mt-4">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-ink-500">
                                <tr>
                                    <th class="px-3 py-2 w-8">#</th>
                                    <th class="px-3 py-2">Description</th>
                                    <th class="px-3 py-2 w-28 text-right">Qty</th>
                                    <th class="px-3 py-2 w-28 text-right">Rate</th>
                                    <th class="px-3 py-2 w-32 text-right">Amount</th>
                                    <th class="px-3 py-2 w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(line, index) in form.lines" :key="index">
                                    <td class="px-3 py-1.5 text-center text-ink-400">{{ index + 1 }}</td>
                                    <td class="px-3 py-1.5">
                                        <input v-model="line.description" type="text" class="w-full rounded border-slate-300 text-sm" :placeholder="line.item_code || 'Description'" />
                                    </td>
                                    <td class="px-3 py-1.5">
                                        <input v-model="line.qty" type="number" min="0" step="any" class="w-full rounded border-slate-300 text-right text-sm" />
                                    </td>
                                    <td class="px-3 py-1.5">
                                        <input v-model="line.rate" type="number" min="0" step="any" class="w-full rounded border-slate-300 text-right text-sm" />
                                    </td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ lineAmount(line) }}</td>
                                    <td class="px-3 py-1.5">
                                        <button v-if="form.lines.length > 1" type="button" class="text-ink-400 hover:text-rose-600" @click="removeLine(index)">×</button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-slate-200">
                                    <td colspan="4" class="px-3 py-2">
                                        <button type="button" class="text-sm text-brand-600 hover:underline" @click="addLine">+ Add line</button>
                                    </td>
                                    <td class="px-3 py-2 text-right font-semibold tnum">{{ subtotal.toFixed(4) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </Card>

            <template #footer>
                <FormFooter
                    :form="form"
                    cancel-href="/supplier-bills"
                    label="Save bill"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
