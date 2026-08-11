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
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import { qty } from '@/plugins/formatting';

const props = defineProps({
    requisition: { type: Object, default: null },
    units: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    uoms: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.requisition));

function blankLine() {
    return { item_id: '', uom_id: '', qty: '', required_by: '', remarks: '' };
}

const form = useForm({
    factory_unit_id: props.requisition?.factory_unit_id ?? props.units[0]?.id ?? '',
    department_id: props.requisition?.department_id ?? '',
    requested_on: props.requisition?.requested_on ?? new Date().toISOString().slice(0, 10),
    required_by: props.requisition?.required_by ?? '',
    remarks: props.requisition?.remarks ?? '',
    lines: props.requisition?.lines?.length
        ? props.requisition.lines.map((line) => ({ ...line }))
        : [blankLine()],
});

function itemFor(line) {
    return props.items.find((item) => item.id === Number(line.item_id));
}

function onItemChange(line) {
    const item = itemFor(line);

    if (item) line.uom_id = line.uom_id || item.base_uom_id;
}

/** BR-25 — what a buyer will actually have to order once the multiple is applied. */
function roundedQty(line) {
    const item = itemFor(line);
    const wanted = Number(line.qty) || 0;

    if (!item || wanted <= 0) return wanted;

    const atLeast = Math.max(wanted, Number(item.min_order_qty) || 0);
    const multiple = Number(item.order_multiple) || 0;

    return multiple > 0 ? Math.ceil(atLeast / multiple) * multiple : atLeast;
}

function addLine() {
    form.lines = [...form.lines, blankLine()];
}

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
}

function submit() {
    isEdit.value
        ? form.put(`/purchase-requisitions/${props.requisition.id}`)
        : form.post('/purchase-requisitions');
}

const columns = [
    { key: 'item_id', label: 'Item', width: '18rem' },
    { key: 'qty', label: 'Quantity', width: '11rem', align: 'right' },
    { key: 'rounded', label: 'Orders as', width: '9rem', align: 'right' },
    { key: 'required_by', label: 'Required by', width: '9rem' },
    { key: 'remarks', label: 'Remarks' },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit requisition' : 'New requisition'" />

        <template #title>
            {{ isEdit ? `Requisition ${requisition.number ?? '(unnumbered)'}` : 'New requisition' }}
        </template>
        <template #subtitle>What the factory is asking for, before anyone agrees to buy it</template>

        <FormLayout @submit="submit">

            <Card title="Raised by">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Factory unit" :error="form.errors.factory_unit_id" required>
                        <SelectInput v-model="form.factory_unit_id" :placeholder="null" :options="units" value-key="id" label-key="name" />
                    </FormField>

                    <FormField label="Department" :error="form.errors.department_id">
                        <SelectInput v-model="form.department_id" :options="departments" value-key="id" label-key="name" />
                    </FormField>

                    <FormField label="Requested on" :error="form.errors.requested_on" required>
                        <DateInput v-model="form.requested_on" />
                    </FormField>

                    <FormField label="Required by" :error="form.errors.required_by">
                        <DateInput v-model="form.required_by" />
                    </FormField>
                </div>
            </Card>

            <Card title="Items needed" rule="BR-25" :padded="false">
                <div class="p-3">
                    <LineItemsTable
                        :columns="columns"
                        :lines="form.lines"
                        :errors="form.errors"
                        add-label="Add item"
                        empty="No items yet"
                    empty-hint="Say what the factory needs and by when; the buyer decides who to order it from."
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
                            <p v-if="itemFor(line)" class="mt-1 truncate text-[11px] text-ink-500">
                                {{ itemFor(line).name }}
                            </p>
                        </template>

                        <template #cell:qty="{ line }">
                            <div class="flex gap-1">
                                <TextInput cell v-model="line.qty" type="number" step="0.000001" numeric />
                                <SelectInput v-model="line.uom_id" :placeholder="null" :options="uoms" value-key="id" label-key="code" class="w-24" />
                            </div>
                        </template>

                        <template #cell:rounded="{ line }">
                            <!-- BR-25: the buyer cannot order 1.3 cartons of yarn. -->
                            <span
                                class="text-sm tnum"
                                :class="roundedQty(line) > Number(line.qty) ? 'font-medium text-amber-700' : 'text-ink-700'"
                            >
                                {{ line.qty ? qty(roundedQty(line)) : '—' }}
                            </span>
                            <p v-if="itemFor(line) && roundedQty(line) > Number(line.qty)" class="text-[10px] text-ink-400">
                                min {{ itemFor(line).min_order_qty }} · × {{ itemFor(line).order_multiple }}
                            </p>
                        </template>

                        <template #cell:required_by="{ line }">
                            <DateInput v-model="line.required_by" />
                        </template>

                        <template #cell:remarks="{ line }">
                            <TextInput cell v-model="line.remarks" />
                        </template>
                    </LineItemsTable>

                    <p v-if="form.errors.lines" class="mt-2 text-xs text-rose-600">{{ form.errors.lines }}</p>
                </div>
            </Card>

            <template #rail>
                <Card title="Requisition">
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Items</dt>
                            <dd class="tnum text-ink-900">{{ form.lines.filter((line) => line.item_id).length }}</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Needed by</dt>
                            <dd class="text-right" :class="form.required_by ? 'text-ink-900' : 'text-ink-400'">
                                {{ form.required_by || 'Not stated' }}
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-[11px] leading-relaxed text-ink-500">
                        Quantities are rounded up to the item's order multiple (BR-25) — a requisition
                        for 7 kg of a 25 kg drum asks for the drum.
                    </p>
                </Card>

                <Card title="Remarks">
                    <FormField :error="form.errors.remarks">
                        <textarea
                            v-model="form.remarks"
                            rows="6"
                            class="form-textarea"
                            placeholder="Why this is needed, and by when."
                        />
                    </FormField>
                </Card>
            </template>

            <template #footer>
                <FormFooter
                    :form="form"
                    cancel-href="/purchase-requisitions"
                    :label="isEdit ? 'Save changes' : 'Save draft'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
