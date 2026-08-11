<script setup>
import { computed } from 'vue';
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
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import { date, money, qty } from '@/plugins/formatting';

const props = defineProps({
    order: { type: Object, default: null },
    openRequisitionLines: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    units: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    paymentTerms: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    uoms: { type: Array, default: () => [] },
    approvalBand: { type: [Number, String], default: 0 },
});

const isEdit = computed(() => Boolean(props.order));
const baseCurrency = computed(() => props.currencies.find((currency) => currency.is_base) ?? null);

function blankLine() {
    return {
        item_id: '',
        uom_id: '',
        qty: '',
        rate: '',
        expected_date: '',
        pr_line_id: null,
        cert_claim: '',
    };
}

const form = useForm({
    supplier_id: props.order?.supplier_id ?? '',
    factory_unit_id: props.order?.factory_unit_id ?? props.units[0]?.id ?? '',
    order_date: props.order?.order_date ?? new Date().toISOString().slice(0, 10),
    expected_date: props.order?.expected_date ?? '',
    currency_id: props.order?.currency_id ?? baseCurrency.value?.id ?? '',
    exchange_rate: props.order?.exchange_rate ?? 1,
    payment_term_id: props.order?.payment_term_id ?? '',
    incoterm: props.order?.incoterm ?? '',
    freight_amount: props.order?.freight_amount ?? 0,
    remarks: props.order?.remarks ?? '',
    lines: props.order?.lines?.length
        ? props.order.lines.map((line) => ({ ...line, cert_claim: line.cert_claim ?? '' }))
        : [blankLine()],
});

const supplier = computed(
    () => props.suppliers.find((row) => row.id === Number(form.supplier_id)) ?? null,
);

/** The supplier's own terms are the sensible default; the buyer can still override them. */
function onSupplierChange() {
    if (!supplier.value) return;

    form.currency_id = supplier.value.currency_id ?? form.currency_id;
    form.payment_term_id = supplier.value.payment_term_id ?? form.payment_term_id;

    if (supplier.value.lead_time_days && !form.expected_date) {
        const due = new Date(form.order_date);

        due.setDate(due.getDate() + Number(supplier.value.lead_time_days));
        form.expected_date = due.toISOString().slice(0, 10);
    }
}

function itemFor(line) {
    return props.items.find((item) => item.id === Number(line.item_id));
}

function onItemChange(line) {
    const item = itemFor(line);

    if (!item) return;

    line.uom_id = line.uom_id || item.base_uom_id;
    line.rate = line.rate || item.std_rate;
}

/** BR-25 — a supplier sells cartons, not fractions of one. */
function roundedQty(line) {
    const item = itemFor(line);
    const wanted = Number(line.qty) || 0;

    if (!item || wanted <= 0) return wanted;

    const atLeast = Math.max(wanted, Number(item.min_order_qty) || 0);
    const multiple = Number(item.order_multiple) || 0;

    return multiple > 0 ? Math.ceil(atLeast / multiple) * multiple : atLeast;
}

function roundLine(line) {
    line.qty = roundedQty(line);
}

/** Pull an approved requisition line straight onto the order, outstanding quantity and all. */
function pullRequisitionLine(prLine) {
    const item = props.items.find((row) => row.id === prLine.item_id);
    const outstanding = Math.max(0, Number(prLine.qty) - Number(prLine.ordered_qty));

    const line = {
        ...blankLine(),
        item_id: prLine.item_id,
        uom_id: prLine.uom_id ?? item?.base_uom_id ?? '',
        qty: outstanding,
        rate: item?.std_rate ?? '',
        expected_date: prLine.required_by ?? '',
        pr_line_id: prLine.id,
    };

    line.qty = roundedQty(line);

    const empty = form.lines.length === 1 && !form.lines[0].item_id;

    form.lines = empty ? [line] : [...form.lines, line];
}

function pulled(prLine) {
    return form.lines.some((line) => line.pr_line_id === prLine.id);
}

function addLine() {
    form.lines = [...form.lines, blankLine()];
}

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
}

const subtotal = computed(() =>
    form.lines.reduce((sum, line) => sum + (Number(line.qty) || 0) * (Number(line.rate) || 0), 0),
);
const total = computed(() => subtotal.value + (Number(form.freight_amount) || 0));

/** 06-rbac §5 — say who has to sign this before the buyer presses submit, not after. */
const approver = computed(() =>
    total.value > Number(props.approvalBand) ? 'Managing Director' : 'Purchase manager',
);

function submit() {
    isEdit.value ? form.put(`/purchase-orders/${props.order.id}`) : form.post('/purchase-orders');
}

const columns = [
    { key: 'item_id', label: 'Item', width: '16rem' },
    { key: 'qty', label: 'Quantity', width: '12rem', align: 'right' },
    { key: 'rate', label: 'Rate', width: '8rem', align: 'right' },
    { key: 'amount', label: 'Amount', width: '8rem', align: 'right' },
    { key: 'expected_date', label: 'Expected', width: '9rem' },
    { key: 'cert_claim', label: 'Claim required', width: '11rem' },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit purchase order' : 'New purchase order'" />

        <template #title>
            {{ isEdit ? `Purchase order ${order.number ?? '(unnumbered)'}` : 'New purchase order' }}
        </template>
        <template #subtitle>Approval routes by value band; the band is a setting, not code</template>

        <FormLayout wide-rail @submit="submit">

            <Card title="Order">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Supplier" :error="form.errors.supplier_id" required>
                        <SelectInput
                            v-model="form.supplier_id"
                            placeholder="— select —"
                            :options="suppliers"
                            value-key="id"
                            label-key="name"
                            @update:model-value="onSupplierChange"
                        />
                        <!-- The state machine refuses submission to an unapproved supplier; warn early. -->
                        <p v-if="supplier && !supplier.is_approved" class="mt-1 text-[11px] text-amber-700">
                            Not an approved supplier — this order cannot be submitted until purchasing approves them.
                        </p>
                    </FormField>

                    <FormField label="Factory unit" :error="form.errors.factory_unit_id" required>
                        <SelectInput v-model="form.factory_unit_id" :placeholder="null" :options="units" value-key="id" label-key="name" />
                    </FormField>

                    <FormField label="Order date" :error="form.errors.order_date" required>
                        <DateInput v-model="form.order_date" />
                    </FormField>

                    <FormField
                        label="Expected"
                        :hint="supplier?.lead_time_days ? `${supplier.lead_time_days} day lead time` : null"
                        :error="form.errors.expected_date"
                    >
                        <DateInput v-model="form.expected_date" />
                    </FormField>

                    <FormField label="Currency" :error="form.errors.currency_id" required>
                        <SelectInput v-model="form.currency_id" :placeholder="null" :options="currencies" value-key="id" label-key="code" />
                    </FormField>

                    <FormField label="Exchange rate" :error="form.errors.exchange_rate" required>
                        <TextInput v-model="form.exchange_rate" type="number" step="0.000001" numeric />
                    </FormField>

                    <FormField label="Payment terms" :error="form.errors.payment_term_id">
                        <SelectInput v-model="form.payment_term_id" :options="paymentTerms" value-key="id" label-key="name" />
                    </FormField>

                    <FormField label="Incoterm" :error="form.errors.incoterm">
                        <TextInput v-model="form.incoterm" placeholder="FOB / CIF" />
                    </FormField>
                </div>
            </Card>

            <Card title="Lines" rule="BR-25" :padded="false">
                <div class="p-3">
                    <LineItemsTable
                        :columns="columns"
                        :lines="form.lines"
                        :errors="form.errors"
                        add-label="Add line"
                        empty="No lines yet"
                empty-hint="Pull an approved requisition line from the panel, or add an item directly."
                        @add="addLine"
                        @remove="removeLine"
                    >
                        <template #cell:item_id="{ line }">
                            <SelectInput
                                v-model="line.item_id"
                                placeholder="— item —"
                                :options="items"
                                value-key="id"
                                label-key="code"
                                @update:model-value="onItemChange(line)"
                            />
                            <p v-if="line.pr_line_id" class="mt-1 text-[10px] text-brand-700">from requisition</p>
                            <p v-else-if="itemFor(line)" class="mt-1 truncate text-[11px] text-ink-500">
                                {{ itemFor(line).name }}
                            </p>
                        </template>

                        <template #cell:qty="{ line }">
                            <div class="flex gap-1">
                                <TextInput cell v-model="line.qty" type="number" step="0.000001" numeric />
                                <SelectInput v-model="line.uom_id" :placeholder="null" :options="uoms" value-key="id" label-key="code" class="w-24" />
                            </div>
                            <!-- Rounding is offered, not forced: the buyer owns the number they sign. -->
                            <button
                                v-if="line.qty && roundedQty(line) > Number(line.qty)"
                                type="button"
                                class="mt-1 text-[10px] text-amber-700 underline"
                                @click="roundLine(line)"
                            >
                                round up to {{ qty(roundedQty(line)) }} (pack multiple)
                            </button>
                        </template>

                        <template #cell:rate="{ line }">
                            <TextInput cell v-model="line.rate" type="number" step="0.0001" numeric />
                        </template>

                        <template #cell:amount="{ line }">
                            <span class="text-sm tnum text-ink-900">
                                {{ money((Number(line.qty) || 0) * (Number(line.rate) || 0)) }}
                            </span>
                        </template>

                        <template #cell:expected_date="{ line }">
                            <DateInput v-model="line.expected_date" />
                        </template>

                        <template #cell:cert_claim="{ line }">
                            <SelectInput
                                v-model="line.cert_claim"
                                placeholder="— none —"
                                :options="['grs', 'rcs', 'ocs', 'fsc', 'oeko_tex'].map((s) => ({ value: s, label: s.replace('_', ' ').toUpperCase() }))"
                            />
                        </template>

                        <template #footer>
                            <tr>
                                <td colspan="3" class="px-3 py-2 text-right text-xs text-ink-700">Subtotal</td>
                                <td class="px-2 py-2 text-right text-sm font-semibold tnum text-ink-900">{{ money(subtotal) }}</td>
                                <td colspan="3" />
                            </tr>
                        </template>
                    </LineItemsTable>

                    <p v-if="form.errors.lines" class="mt-2 text-xs text-rose-600">{{ form.errors.lines }}</p>

                    <div class="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-ink-700">
                        A line that demands a certification claim makes the GRN's certification fields
                        mandatory — that is the only door a claim enters the system through (Gate 2).
                    </div>
                </div>
            </Card>

            <template #rail>
                <Card title="Value" rule="06-rbac §5">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-ink-500">Goods</dt>
                            <dd class="tnum text-ink-900">{{ money(subtotal) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-ink-500">Freight</dt>
                            <dd class="w-28">
                                <TextInput v-model="form.freight_amount" type="number" step="0.0001" numeric />
                            </dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-1.5 font-semibold">
                            <dt class="text-ink-800">Total</dt>
                            <dd class="tnum text-ink-900">{{ money(total) }}</dd>
                        </div>
                    </dl>

                    <div class="mt-3 rounded-md bg-brand-50 px-3 py-2">
                        <p class="text-[10px] tracking-wider text-brand-700 uppercase">Needs approval from</p>
                        <p class="text-sm font-semibold text-brand-800">{{ approver }}</p>
                        <p class="mt-0.5 text-[11px] text-ink-500">
                            Manager band {{ money(approvalBand) }}
                        </p>
                    </div>
                </Card>

                <!-- Requisitions are where the demand came from; retyping it loses the link. -->
                <Card
                    v-if="openRequisitionLines.length"
                    title="Open requisition lines"
                    subtitle="Approved and not yet fully ordered"
                    :padded="false"
                >
                    <div class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
                        <div
                            v-for="prLine in openRequisitionLines"
                            :key="prLine.id"
                            class="flex items-start gap-2 px-3 py-2"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-ink-900">{{ prLine.item_code }}</p>
                                <p class="truncate text-[11px] text-ink-500">
                                    {{ prLine.pr_number }} ·
                                    {{ qty(Number(prLine.qty) - Number(prLine.ordered_qty)) }} outstanding
                                    <span v-if="prLine.required_by"> · by {{ date(prLine.required_by) }}</span>
                                </p>
                            </div>

                            <Badge v-if="pulled(prLine)" tone="success" label="added" />
                            <Button v-else size="sm" @click="pullRequisitionLine(prLine)">Pull</Button>
                        </div>
                    </div>
                </Card>

                <Card title="Remarks">
                    <FormField :error="form.errors.remarks">
                        <textarea
                            v-model="form.remarks"
                            rows="6"
                            class="form-textarea"
                            placeholder="Anything the supplier or the store keeper needs told."
                        />
                    </FormField>
                </Card>
            </template>

            <template #footer>
                <FormFooter
                    :form="form"
                    cancel-href="/purchase-orders"
                    :summary="`${money(total)} · approved by the ${approver}`"
                    :label="isEdit ? 'Save changes' : 'Save draft'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
