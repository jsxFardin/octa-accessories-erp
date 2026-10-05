<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import DateInput from '@/Components/Ui/DateInput.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { money, pcs, todayIso } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    invoices: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    preselectInvoiceId: { type: Number, default: null },
    invoice: { type: Object, default: null },
    lines: { type: Array, default: () => [] },
});

const form = useForm({
    sales_invoice_id: props.preselectInvoiceId ?? props.invoice?.id ?? '',
    warehouse_id: props.warehouses.find((w) => w.kind === 'finished_goods')?.id ?? '',
    // The local calendar day. `toISOString()` is UTC, which before 06:00 in Dhaka is yesterday.
    returned_on: todayIso(),
    reason: '',
    lines: [],
});

/**
 * Choosing an invoice asks the server for its lines rather than guessing them client-side:
 * the returnable balance is `invoiced − already returned`, and that is a server figure
 * re-derived under a lock before anything is accepted.
 *
 * State is preserved across that visit. Rebuilding the page threw away the reason and date
 * already typed, which is the wrong reward for filling the form in from the top.
 */
function chooseInvoice(id) {
    if (!id) return;
    router.get(`/sales-returns/invoice/${id}`, {}, { preserveState: true, preserveScroll: true, replace: true });
}

watch(() => form.sales_invoice_id, (id) => {
    if (id && id !== props.invoice?.id) chooseInvoice(id);
});

// Arriving with `?invoice=` picks the invoice but nothing has asked for its lines yet.
onMounted(() => {
    if (form.sales_invoice_id && form.sales_invoice_id !== props.invoice?.id) chooseInvoice(form.sales_invoice_id);
});

function toRow(line) {
    return {
        sales_invoice_line_id: line.id,
        line_no: line.line_no,
        description: line.description,
        product_code: line.product_code,
        invoiced: Number(line.qty),
        already: Number(line.returned_qty),
        returnable: Number(line.returnable),
        rate_per_m: Number(line.rate_per_m),
        lots: line.lots ?? [],
        lot_id: line.lots?.length === 1 ? line.lots[0].id : '',
        qty: '',
    };
}

/** One row per invoice line, with a quantity the person filling it in types. */
const rows = ref(props.lines.map(toRow));

// The lines arrive after the page has mounted, so they are followed rather than read once.
watch(() => props.lines, (lines) => {
    rows.value = lines.map(toRow);
});

const chosen = computed(() => rows.value.filter((r) => Number(r.qty) > 0));

const creditValue = computed(
    () => chosen.value.reduce((total, r) => total + (Number(r.qty) / 1000) * r.rate_per_m, 0),
);

const overReturned = computed(() => rows.value.filter((r) => Number(r.qty) > r.returnable));

function submit() {
    form
        .transform((data) => ({
            ...data,
            lines: chosen.value.map((r) => ({
                sales_invoice_line_id: r.sales_invoice_line_id,
                lot_id: r.lot_id || null,
                qty: Number(r.qty),
            })),
        }))
        .post('/sales-returns');
}

/** What stands between the user and the save button, in words. */
const blockedBy = computed(() => {
    if (!form.sales_invoice_id) return 'Pick the invoice these goods were billed on.';
    if (!form.warehouse_id) return 'Choose the warehouse the goods are going back into.';
    if (!chosen.value.length) return 'Enter the quantity that came back on at least one line.';
    if (overReturned.value.length) {
        return `Line ${overReturned.value.map((r) => r.line_no).join(', ')} asks for more than is left to return.`;
    }
    if (!form.reason.trim()) return 'Give the reason for the return.';

    return null;
});

const selectedInvoice = computed(
    () => props.invoice ?? props.invoices.find((i) => i.id === form.sales_invoice_id) ?? null,
);
</script>

<template>
    <AppLayout crumb="New return">
        <Head title="New customer return" />

        <template #title>New customer return</template>
        <template #subtitle>Goods sent back after delivery. The invoice stays exactly as it is.</template>

        <FormLayout @submit="submit">
            <Card title="What came back">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Invoice" :error="form.errors.sales_invoice_id" required class="lg:col-span-2">
                        <SelectInput
                            v-model="form.sales_invoice_id"
                            :options="invoices.map((i) => ({
                                value: i.id,
                                label: `${i.number} · ${i.customer_name} · ${i.currency} ${i.total}`,
                                hint: i.status,
                            }))"
                            hint-key="hint"
                            placeholder="Pick the invoice these goods were billed on…"
                        />
                    </FormField>

                    <FormField label="Back into" :error="form.errors.warehouse_id" required>
                        <SelectInput
                            v-model="form.warehouse_id"
                            :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))"
                            :placeholder="null"
                        />
                    </FormField>

                    <FormField label="Returned on" :error="form.errors.returned_on" required>
                        <DateInput v-model="form.returned_on" />
                    </FormField>
                </div>

                <!--
                    The point the whole screen has to make. A paid invoice is a fact — the money
                    arrived — and a return does not undo it. Saying so here is cheaper than
                    explaining it afterwards.
                -->
                <p v-if="selectedInvoice" class="mt-3 rounded-md bg-slate-50 px-3 py-2 text-xs text-ink-600">
                    Invoice <span class="font-medium text-ink-900">{{ selectedInvoice.number }}</span>
                    is <Badge :status="selectedInvoice.status" /> and stays that way. This return records the
                    goods; a credit note for their value is drafted when it is posted, and where that credit
                    goes is decided separately.
                </p>

                <FormField label="Reason" :error="form.errors.reason" required class="mt-3">
                    <textarea
                        v-model="form.reason"
                        rows="2"
                        class="form-textarea"
                        placeholder="Customer returned two cartons — wrong size on the care label…"
                    />
                </FormField>
            </Card>

            <Card v-if="rows.length" title="Lines" subtitle="Only what is still returnable — invoiced less what has already come back">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 text-xs text-ink-500">
                        <tr>
                            <th class="px-2 py-2 text-left">#</th>
                            <th class="px-2 py-2 text-left">Product</th>
                            <th class="px-2 py-2 text-right">Invoiced</th>
                            <th class="px-2 py-2 text-right">Returned</th>
                            <th class="px-2 py-2 text-right">Returnable</th>
                            <th class="px-2 py-2 text-left">Lot</th>
                            <th class="px-2 py-2 text-right">Qty back</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.sales_invoice_line_id" class="border-b border-slate-100">
                            <td class="px-2 py-2 text-ink-500">{{ row.line_no }}</td>
                            <td class="px-2 py-2">
                                <span class="font-medium text-ink-900">{{ row.product_code }}</span>
                                <span class="text-ink-500"> {{ row.description }}</span>
                            </td>
                            <td class="px-2 py-2 text-right tnum">{{ pcs(row.invoiced) }}</td>
                            <td class="px-2 py-2 text-right tnum text-ink-500">{{ pcs(row.already) }}</td>
                            <td class="px-2 py-2 text-right tnum font-medium">{{ pcs(row.returnable) }}</td>
                            <td class="px-2 py-2">
                                <SelectInput
                                    v-if="row.lots.length"
                                    v-model="row.lot_id"
                                    cell
                                    :options="row.lots.map((l) => ({ value: l.id, label: l.lot_no }))"
                                    placeholder="Which lot…"
                                />
                                <span v-else class="text-xs text-ink-400">no dispatched lot on file</span>
                            </td>
                            <td class="px-2 py-2 text-right">
                                <TextInput
                                    v-model="row.qty"
                                    cell
                                    type="number"
                                    min="0"
                                    step="any"
                                    numeric
                                    :max="row.returnable"
                                    :placeholder="`≤ ${row.returnable}`"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-if="overReturned.length" class="mt-2 text-xs text-rose-700">
                    Line {{ overReturned.map((r) => r.line_no).join(', ') }} asks for more than is left to return.
                </p>
            </Card>

            <template #rail>
                <Card title="Return">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-ink-500">Lines</dt>
                            <dd class="font-medium tnum">{{ chosen.length }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-ink-500">Credit value</dt>
                            <dd class="font-medium tnum">{{ money(creditValue) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs leading-relaxed text-ink-500">
                        Valued at the rate each line was invoiced at, not today's price list. A draft credit
                        note for this amount is raised when the return is posted.
                    </p>
                </Card>
            </template>

            <template #footer>
                <FormFooter
                    :form="form"
                    cancel-href="/sales-returns"
                    label="Save draft"
                    :summary="`${chosen.length} ${chosen.length === 1 ? 'line' : 'lines'}`"
                    :disabled="blockedBy !== null"
                    :disabled-reason="blockedBy"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
