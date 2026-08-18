<script setup>
import { computed, reactive } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, money, qty } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';

const props = defineProps({
    rfq: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    quotations: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    quoteThreshold: { type: [Number, String], default: 50000 },
});

const quoteForm = useForm({
    supplier_id: '',
    quoted_on: new Date().toISOString().slice(0, 10),
    valid_until: '',
    currency_id: '',
    lead_time_days: '',
    remarks: '',
    lines: props.lines.map((line) => ({
        item_id: line.item_id,
        uom_id: line.uom_id,
        qty: line.qty,
        rate: '',
    })),
});

const override = reactive({ quotation_id: null, reason: '' });

function transition(to) {
    router.post(`/rfqs/${props.rfq.id}/transition`, { to }, { preserveScroll: true });
}

function onSupplierChange() {
    const supplier = props.suppliers.find((row) => Number(row.id) === Number(quoteForm.supplier_id));
    if (!supplier) return;
    quoteForm.currency_id = supplier.currency_id ?? quoteForm.currency_id;
    quoteForm.lead_time_days = supplier.lead_time_days ?? quoteForm.lead_time_days;
}

function recordQuote() {
    quoteForm.post(`/rfqs/${props.rfq.id}/quotations`, { preserveScroll: true });
}

function selectWinner(quotation) {
    override.quotation_id = quotation.id;
    router.post(`/rfqs/${props.rfq.id}/select`, {
        quotation_id: quotation.id,
        override_reason: override.reason || null,
    }, { preserveScroll: true });
}

function raisePo() {
    router.post(`/rfqs/${props.rfq.id}/purchase-order`);
}

const needsThree = computed(() => {
    const selected = props.quotations.find((q) => q.is_selected) ?? props.quotations[0];
    return Number(selected?.total ?? 0) > Number(props.quoteThreshold) && props.quotations.length < 3;
});
</script>

<template>
    <AppLayout>
        <Head :title="rfq.number ?? 'RFQ'" />

        <template #title>{{ rfq.number ?? '(draft RFQ)' }}</template>
        <template #subtitle>
            <span v-if="rfq.requisition">{{ rfq.requisition.number }} · </span>
            Issued {{ date(rfq.issued_on) }}
            <span v-if="rfq.respond_by"> · respond by {{ date(rfq.respond_by) }}</span>
        </template>

        <template #actions>
            <Badge :status="rfq.status" />
            <Button v-if="rfq.status === 'draft' && can('rfq.update')" size="sm" :href="`/rfqs/${rfq.id}/edit`">Edit</Button>
            <Button v-if="availableTransitions.includes('issued')" size="sm" variant="primary" @click="transition('issued')">Issue</Button>
            <Button v-if="rfq.status !== 'draft'" size="sm" :href="`/rfqs/${rfq.id}/compare`">Compare</Button>
            <Button v-if="quotations.some((q) => q.is_selected) && can('purchase_order.create') && rfq.status === 'issued'" size="sm" variant="success" @click="raisePo">
                Raise PO
            </Button>
            <Button v-if="availableTransitions.includes('closed')" size="sm" @click="transition('closed')">Close</Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="transition('cancelled')">Cancel</Button>
        </template>

        <div class="space-y-4">
            <Card title="Lines" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'line_no', label: '#' },
                        { key: 'item_code', label: 'Item' },
                        { key: 'qty', label: 'Qty', align: 'right' },
                    ]"
                    :rows="lines"
                    row-key="id"
                    empty="No lines."
                    dense
                >
                    <template #cell:item_code="{ row }">
                        <span class="font-medium">{{ row.item_code }}</span>
                        <span class="text-ink-500"> {{ row.item_name }}</span>
                    </template>
                    <template #cell:qty="{ row }">{{ qty(row.qty) }} {{ row.uom }}</template>
                </DataTable>
            </Card>

            <Card title="Quotations">
                <p v-if="needsThree" class="mb-3 rounded bg-amber-50 px-2 py-1.5 text-xs text-amber-900">
                    Quoted value is above {{ money(quoteThreshold) }}. Three quotations are required, or an override reason.
                </p>
                <DataTable
                    :columns="[
                        { key: 'supplier', label: 'Supplier' },
                        { key: 'total', label: 'Total', align: 'right' },
                        { key: 'lead_time_days', label: 'Lead days', align: 'right' },
                        { key: 'currency', label: 'Currency' },
                        { key: 'is_selected', label: 'Winner' },
                    ]"
                    :rows="quotations"
                    row-key="id"
                    empty="No quotations recorded yet."
                    dense
                >
                    <template #cell:supplier="{ row }">{{ row.supplier?.name }}</template>
                    <template #cell:total="{ value }">{{ money(value) }}</template>
                    <template #cell:is_selected="{ row }">
                        <Badge v-if="row.is_selected" tone="success" label="Selected" />
                        <Button
                            v-else-if="rfq.status === 'issued' && can('rfq.update')"
                            size="sm"
                            @click="selectWinner(row)"
                        >
                            Select
                        </Button>
                    </template>
                </DataTable>
                <FormField v-if="rfq.status === 'issued'" class="mt-3" label="Override reason (if fewer than three quotes above the threshold)">
                    <TextInput v-model="override.reason" />
                </FormField>
            </Card>

            <Card v-if="rfq.status === 'issued' && can('rfq.update')" title="Record a quotation">
                <div class="grid gap-3 sm:grid-cols-3">
                    <FormField label="Supplier" :error="quoteForm.errors.supplier_id" required>
                        <SelectInput
                            v-model="quoteForm.supplier_id"
                            :options="suppliers"
                            value-key="id"
                            label-key="name"
                            @update:model-value="onSupplierChange"
                        />
                    </FormField>
                    <FormField label="Currency" :error="quoteForm.errors.currency_id" required>
                        <SelectInput v-model="quoteForm.currency_id" :options="currencies" value-key="id" label-key="code" />
                    </FormField>
                    <FormField label="Lead time (days)" :error="quoteForm.errors.lead_time_days">
                        <TextInput v-model="quoteForm.lead_time_days" type="number" min="0" />
                    </FormField>
                    <FormField label="Quoted on">
                        <DateInput v-model="quoteForm.quoted_on" />
                    </FormField>
                    <FormField label="Valid until">
                        <DateInput v-model="quoteForm.valid_until" />
                    </FormField>
                    <FormField label="Remarks">
                        <TextInput v-model="quoteForm.remarks" />
                    </FormField>
                </div>
                <div class="mt-4 space-y-2">
                    <div v-for="(line, index) in quoteForm.lines" :key="line.item_id" class="grid grid-cols-3 gap-2 text-sm">
                        <p class="col-span-1 self-center">{{ lines[index]?.item_code }} · {{ qty(line.qty) }}</p>
                        <FormField :error="quoteForm.errors[`lines.${index}.rate`]" label="Rate">
                            <TextInput v-model="line.rate" type="number" min="0" step="any" />
                        </FormField>
                    </div>
                </div>
                <Button class="mt-4" variant="primary" :disabled="quoteForm.processing" @click="recordQuote">Save quotation</Button>
            </Card>
        </div>
    </AppLayout>
</template>
