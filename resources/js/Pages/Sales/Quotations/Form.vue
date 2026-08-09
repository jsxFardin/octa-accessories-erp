<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import LineItemsTable from '@/Components/Ui/LineItemsTable.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';

const props = defineProps({
    quotation: { type: Object, default: null },
    inquiryId: { type: Number, default: null },
    customers: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    defaultMarginPct: { type: [Number, String], default: 20 },
    marginFloorPct: { type: [Number, String], default: 12 },
});

const isEdit = computed(() => Boolean(props.quotation));

function blankLine() {
    return {
        product_id: '',
        product_spec_id: '',
        description: '',
        qty: '',
        margin_pct: Number(props.defaultMarginPct),
        rate_per_m: '',
        tooling_charge: 0,
        lead_time_days: '',
    };
}

const form = useForm({
    inquiry_id: props.quotation?.inquiry_id ?? props.inquiryId ?? '',
    customer_id: props.quotation?.customer_id ?? '',
    quotation_date: props.quotation?.quotation_date ?? new Date().toISOString().slice(0, 10),
    valid_until: props.quotation?.valid_until ?? '',
    currency_id: props.quotation?.currency_id ?? props.currencies.find((c) => c.is_base)?.id ?? '',
    exchange_rate: props.quotation?.exchange_rate ?? 1,
    terms: props.quotation?.terms ?? '',
    lines: props.quotation?.lines?.length
        ? props.quotation.lines.map((line) => ({ ...line }))
        : [blankLine()],
});

/** Products belong to exactly one customer (P1), so the picker narrows with the header. */
const availableProducts = computed(() =>
    form.customer_id
        ? props.products.filter((product) => product.customer_id === Number(form.customer_id))
        : props.products,
);

// --- Live cost sheet ---------------------------------------------------------------------
// The rate is not typed; it is computed from the spec, the routing and the margin, and the
// merchandiser sees which rule produced each number (08-architecture §4).
const sheets = ref({});
const pending = ref({});

async function priceLine(index) {
    const line = form.lines[index];

    if (!line.product_id || !line.qty) {
        return;
    }

    pending.value = { ...pending.value, [index]: true };

    try {
        const response = await fetch('/cost-sheets/calculate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                product_id: Number(line.product_id),
                product_spec_id: line.product_spec_id || null,
                qty: Number(line.qty),
                margin_pct: Number(line.margin_pct),
                exchange_rate: Number(form.exchange_rate) || 1,
                currency: props.currencies.find((c) => c.id === Number(form.currency_id))?.code,
            }),
        });

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            sheets.value = { ...sheets.value, [index]: { error: Object.values(payload.errors ?? {}).flat()[0] ?? 'Could not price this line.' } };

            return;
        }

        const payload = await response.json();
        sheets.value = { ...sheets.value, [index]: payload };

        // The computed rate is the answer; typing over it is what BR-20 exists to prevent.
        form.lines[index].rate_per_m = payload.sheet.rate_per_m_in_currency;
    } finally {
        pending.value = { ...pending.value, [index]: false };
    }
}

/** Re-price when the inputs that feed a rate change — not on every keystroke elsewhere. */
watch(
    () => form.lines.map((line) => `${line.product_id}|${line.qty}|${line.margin_pct}`).join(','),
    () => form.lines.forEach((_, index) => priceLine(index)),
);

watch(() => form.exchange_rate, () => form.lines.forEach((_, index) => priceLine(index)));

function addLine() {
    form.lines = [...form.lines, blankLine()];
}

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
    sheets.value = {};
    form.lines.forEach((_, i) => priceLine(i));
}

function lineTotal(line) {
    return (Number(line.qty) || 0) / 1000 * (Number(line.rate_per_m) || 0) + (Number(line.tooling_charge) || 0);
}

const subtotal = computed(() => form.lines.reduce((sum, line) => sum + lineTotal(line), 0));

const currencyCode = computed(
    () => props.currencies.find((c) => c.id === Number(form.currency_id))?.code ?? '',
);

const belowFloor = computed(() =>
    form.lines.some((line) => Number(line.margin_pct) < Number(props.marginFloorPct)),
);

function submit() {
    isEdit.value
        ? form.put(`/quotations/${props.quotation.id}`)
        : form.post('/quotations');
}

const columns = [
    { key: 'product_id', label: 'Product', width: '15rem' },
    { key: 'description', label: 'Description' },
    { key: 'qty', label: 'Quantity', width: '8rem', align: 'right' },
    { key: 'margin_pct', label: 'Margin %', width: '7rem', align: 'right' },
    { key: 'rate_per_m', label: 'Rate /M', width: '9rem', align: 'right' },
    { key: 'line_total', label: 'Line value', width: '9rem', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit quotation' : 'New quotation'" />

        <template #title>
            {{ isEdit ? `Quotation ${quotation.number ?? '(unnumbered)'}` : 'New quotation' }}
        </template>
        <template #subtitle>Each line is priced by the cost sheet, not typed by hand</template>

        <template #actions>
            <Button href="/quotations">Cancel</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">
                {{ isEdit ? 'Save changes' : 'Save draft' }}
            </Button>
        </template>

        <form class="space-y-4" @submit.prevent="submit">
            <Card title="Header">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <FormField label="Customer" :error="form.errors.customer_id" required>
                        <SelectInput
                            v-model="form.customer_id"
                            placeholder="— select —"
                            :options="customers"
                            value-key="id"
                            label-key="name"
                        />
                    </FormField>

                    <FormField label="Quotation date" :error="form.errors.quotation_date" required>
                        <DateInput v-model="form.quotation_date" />
                    </FormField>

                    <FormField label="Valid until" :error="form.errors.valid_until">
                        <DateInput v-model="form.valid_until" />
                    </FormField>

                    <FormField label="Currency" :error="form.errors.currency_id" required>
                        <SelectInput
                            v-model="form.currency_id"
                            :placeholder="null"
                            :options="currencies"
                            value-key="id"
                            label-key="code"
                        />
                    </FormField>

                    <FormField
                        label="Exchange rate"
                        rule="BR-22"
                        hint="Snapshotted on send; a reprint never re-reads it."
                        :error="form.errors.exchange_rate"
                        required
                    >
                        <TextInput v-model="form.exchange_rate" type="number" step="0.000001" numeric />
                    </FormField>
                </div>
            </Card>

            <Card title="Lines" rule="BR-20" :padded="false">
                <div class="p-3">
                    <LineItemsTable
                        :columns="columns"
                        :lines="form.lines"
                        :errors="form.errors"
                        add-label="Add line"
                        empty="A quotation needs at least one line."
                        @add="addLine"
                        @remove="removeLine"
                    >
                        <template #cell:product_id="{ line, index }">
                            <SelectInput
                                v-model="line.product_id"
                                placeholder="— product —"
                                :options="availableProducts"
                                value-key="id"
                                label-key="code"
                            />
                            <p v-if="line.product_id" class="mt-1 truncate text-[11px] text-ink-500">
                                {{ availableProducts.find((p) => p.id === Number(line.product_id))?.name }}
                            </p>
                            <p v-if="sheets[index]?.error" class="mt-1 text-[11px] text-rose-600">
                                {{ sheets[index].error }}
                            </p>
                        </template>

                        <template #cell:description="{ line }">
                            <TextInput v-model="line.description" placeholder="As it should read on the quotation" />
                        </template>

                        <template #cell:qty="{ line }">
                            <TextInput v-model="line.qty" type="number" numeric min="1" />
                        </template>

                        <template #cell:margin_pct="{ line }">
                            <TextInput v-model="line.margin_pct" type="number" step="0.01" numeric />
                            <p
                                v-if="Number(line.margin_pct) < Number(marginFloorPct)"
                                class="mt-1 text-[11px] text-amber-700"
                            >
                                below {{ marginFloorPct }}% floor
                            </p>
                        </template>

                        <template #cell:rate_per_m="{ line, index }">
                            <div class="text-right">
                                <span v-if="pending[index]" class="text-xs text-ink-400">pricing…</span>
                                <span v-else class="text-sm font-semibold tnum text-ink-900">
                                    {{ line.rate_per_m ? ratePerM(line.rate_per_m) : '—' }}
                                </span>
                                <p class="text-[10px] text-ink-400">computed</p>
                            </div>
                        </template>

                        <template #cell:line_total="{ line }">
                            <span class="text-sm tnum text-ink-800">{{ money(lineTotal(line)) }}</span>
                        </template>

                        <template #footer>
                            <tr>
                                <td colspan="5" class="px-3 py-2 text-right text-xs text-ink-700">Subtotal</td>
                                <td class="px-2 py-2 text-right text-sm font-semibold tnum text-ink-900">
                                    {{ money(subtotal, currencyCode) }}
                                </td>
                                <td />
                            </tr>
                        </template>
                    </LineItemsTable>

                    <p v-if="form.errors.lines" class="mt-2 text-xs text-rose-600">{{ form.errors.lines }}</p>

                    <p v-if="belowFloor" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        A line is priced below the {{ marginFloorPct }}% margin floor. Sending this
                        quotation needs the <span class="font-mono">cost_sheet.override_margin</span> permission.
                    </p>
                </div>
            </Card>

            <!-- The cost breakdown behind whichever line is priced, with its rule references. -->
            <Card
                v-for="(sheet, index) in sheets"
                :key="`sheet-${index}`"
                v-show="sheet.sheet"
                :title="`Line ${Number(index) + 1} — cost breakdown`"
                rule="BR-14 … BR-22"
                :padded="false"
            >
                <div v-if="sheet.sheet" class="grid gap-0 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <table class="min-w-full text-xs">
                            <thead class="bg-slate-50 text-ink-700">
                                <tr>
                                    <th class="px-3 py-1.5 text-left">Cost type</th>
                                    <th class="px-3 py-1.5 text-right">Qty</th>
                                    <th class="px-3 py-1.5 text-right">Rate</th>
                                    <th class="px-3 py-1.5 text-right">Amount</th>
                                    <th class="px-3 py-1.5 text-left">Rule</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="cl in sheet.sheet.lines" :key="cl.seq">
                                    <td class="px-3 py-1.5 font-medium text-ink-800">{{ titleCase(cl.cost_type) }}</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ qty(cl.qty) }}</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ Number(cl.rate).toFixed(4) }}</td>
                                    <td class="px-3 py-1.5 text-right font-medium tnum">{{ money(cl.amount) }}</td>
                                    <td class="px-3 py-1.5">
                                        <span class="rounded bg-slate-100 px-1 font-mono text-[10px] text-ink-700">
                                            {{ cl.formula_ref }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-slate-200 p-3 text-sm lg:border-t-0 lg:border-l">
                        <dl class="space-y-1.5">
                            <div class="flex justify-between">
                                <dt class="text-ink-500">Gross metres</dt>
                                <dd class="tnum">{{ qty(sheet.sheet.lines.find((l) => l.cost_type === 'material_ribbon')?.qty ?? 0) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-ink-500">Total cost</dt>
                                <dd class="tnum font-medium">{{ money(sheet.sheet.total_cost) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-ink-500">Unit cost</dt>
                                <dd class="tnum">{{ Number(sheet.sheet.unit_cost).toFixed(6) }}</dd>
                            </div>
                            <div class="flex justify-between rounded bg-brand-50 px-2 py-1">
                                <dt class="font-semibold text-brand-900">Rate / M</dt>
                                <dd class="tnum font-semibold text-brand-900">
                                    {{ ratePerM(sheet.sheet.rate_per_m_in_currency) }}
                                </dd>
                            </div>
                        </dl>

                        <p class="mt-2 text-[11px] text-ink-500">
                            Margin is applied on price — unit cost × 1000 ÷ (1 − margin) — not on cost.
                        </p>

                        <p
                            v-for="warning in sheet.warnings"
                            :key="warning"
                            class="mt-2 rounded bg-amber-50 px-2 py-1 text-[11px] text-amber-900"
                        >
                            {{ warning }}
                        </p>
                    </div>
                </div>
            </Card>

            <Card title="Terms">
                <FormField :error="form.errors.terms">
                    <textarea v-model="form.terms" rows="3" class="form-textarea" />
                </FormField>
            </Card>
        </form>
    </AppLayout>
</template>
