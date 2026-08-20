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
import { todayIso, isoDate } from '@/plugins/formatting';

const props = defineProps({
    rfq: { type: Object, default: null },
    requisition: { type: Object, default: null },
    lines: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    uoms: { type: Array, default: () => [] },
    requisitions: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.rfq?.id));

function blankLine() {
    return { item_id: '', uom_id: '', qty: '' };
}

const form = useForm({
    pr_id: props.rfq?.pr_id ?? props.requisition?.id ?? '',
    issued_on: isoDate(props.rfq?.issued_on) || todayIso(),
    respond_by: props.rfq?.respond_by ?? '',
    lines: (props.rfq?.lines?.length
        ? props.rfq.lines
        : (props.lines?.length ? props.lines : [blankLine()])
    ).map((line) => ({ ...line })),
});

function itemFor(line) {
    return props.items.find((item) => item.id === Number(line.item_id));
}

function onItemChange(line) {
    const item = itemFor(line);
    if (item) line.uom_id = line.uom_id || item.base_uom_id;
}

function addLine() {
    form.lines = [...form.lines, blankLine()];
}

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
}

function submit() {
    isEdit.value ? form.put(`/rfqs/${props.rfq.id}`) : form.post('/rfqs');
}

const columns = [
    { key: 'item_id', label: 'Item', width: '18rem' },
    { key: 'qty', label: 'Quantity', width: '14rem', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit RFQ' : 'New RFQ'" />

        <template #title>{{ isEdit ? (rfq.number ?? 'RFQ') : 'New RFQ' }}</template>
        <template #subtitle>What every invited supplier is asked to quote.</template>

        <FormLayout @submit="submit">
            <Card title="RFQ">
                <div class="grid gap-3 sm:grid-cols-3">
                    <FormField label="Requisition" :error="form.errors.pr_id">
                        <SelectInput
                            v-model="form.pr_id"
                            :options="requisitions"
                            value-key="id"
                            label-key="number"
                        />
                    </FormField>
                    <FormField label="Issued on" :error="form.errors.issued_on">
                        <DateInput v-model="form.issued_on" />
                    </FormField>
                    <FormField label="Respond by" :error="form.errors.respond_by">
                        <DateInput v-model="form.respond_by" />
                    </FormField>
                </div>
            </Card>

            <Card title="Items" :padded="false">
                <div class="p-3">
                    <LineItemsTable
                        :columns="columns"
                        :lines="form.lines"
                        :errors="form.errors"
                        add-label="Add item"
                        empty="No items yet"
                        empty-hint="Copy from an approved requisition, or add the items suppliers should quote."
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
                                hint-key="name"
                                @update:model-value="onItemChange(line)"
                            />
                        </template>
                        <template #cell:qty="{ line }">
                            <div class="flex gap-1">
                                <TextInput cell v-model="line.qty" type="number" step="0.000001" numeric />
                                <SelectInput v-model="line.uom_id" :placeholder="null" :options="uoms" value-key="id" label-key="code" class="w-24" />
                            </div>
                        </template>
                    </LineItemsTable>
                </div>
            </Card>

            <template #footer>
                <FormFooter
                    :form="form"
                    cancel-href="/rfqs"
                    :label="isEdit ? 'Save changes' : 'Save draft'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
