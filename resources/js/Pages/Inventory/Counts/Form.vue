<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import { todayIso, isoDate } from '@/plugins/formatting';

const props = defineProps({
    count: { type: Object, default: null },
    warehouses: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.count?.id));

const form = useForm(
    isEdit.value
        ? {
              lines: (props.count?.lines ?? []).map((line) => ({
                  id: line.id,
                  lot_id: line.lot_id,
                  lot_no: line.lot_no,
                  item_code: line.item_code,
                  bin_code: line.bin_code,
                  counted_qty: line.counted_qty ?? '',
                  remarks: line.remarks ?? '',
              })),
          }
        : {
              warehouse_id: props.warehouses[0]?.id ?? '',
              counted_on: isoDate(props.count?.counted_on) || todayIso(),
          },
);

/** What the server holds for each line, to tell a keyed figure from an untouched one. */
const saved = new Map((props.count?.lines ?? []).map((line) => [
    line.id,
    { counted_qty: line.counted_qty ?? '', remarks: line.remarks ?? '' },
]));

function isCounted(line) {
    return line.counted_qty !== '' && line.counted_qty !== null;
}

function isChanged(line) {
    const before = saved.get(line.id);

    return !before
        || String(line.counted_qty ?? '') !== String(before.counted_qty)
        || (line.remarks ?? '') !== before.remarks;
}

const countedLines = computed(() => (form.lines ?? []).filter(isCounted).length);
const totalLines = computed(() => form.lines?.length ?? 0);

/** The ids sent on the last save, in order — server errors come back keyed by that position. */
const sentIds = ref([]);

function lineError(line, field) {
    const position = sentIds.value.indexOf(line.id);

    return position === -1 ? null : form.errors[`lines.${position}.${field}`];
}

function submit() {
    if (isEdit.value) {
        // Only the lines somebody keyed are sent. A blank line stays uncounted rather than
        // failing the save, and a second counter does not overwrite the first one's lines.
        const changed = form.lines.filter(isChanged);

        sentIds.value = changed.map((line) => line.id);

        form
            .transform(() => ({
                lines: changed.map((line) => ({
                    id: line.id,
                    counted_qty: isCounted(line) ? line.counted_qty : null,
                    remarks: line.remarks,
                })),
            }))
            .put(`/physical-counts/${props.count.id}`, { preserveScroll: true });

        return;
    }

    form.post('/physical-counts');
}
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? (count?.number ?? 'Physical count') : 'New physical count'" />

        <template #title>{{ isEdit ? (count?.number ?? 'Physical count') : 'New physical count' }}</template>
        <template #subtitle>
            <template v-if="isEdit">
                {{ count?.warehouse?.name }} · enter counted quantities without seeing system stock
            </template>
            <template v-else>
                Choose a warehouse. Starting the count will freeze every available lot in it.
            </template>
        </template>

        <FormLayout @submit="submit">
            <Card v-if="!isEdit" title="Count setup">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Warehouse" :error="form.errors.warehouse_id" required>
                        <SelectInput
                            v-model="form.warehouse_id"
                            :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` }))"
                        />
                    </FormField>
                    <FormField label="Count date" :error="form.errors.counted_on">
                        <DateInput v-model="form.counted_on" />
                    </FormField>
                </div>
            </Card>

            <Card
                v-else
                title="Count lines"
                :subtitle="`${countedLines} of ${totalLines} counted. You can save part-way and carry on later — system quantities stay hidden until reconciliation.`"
            >
                <p v-if="form.errors.lines" role="alert" class="mb-3 text-xs text-rose-700">{{ form.errors.lines }}</p>
                <div class="space-y-3">
                    <div
                        v-for="line in form.lines"
                        :key="line.id"
                        class="grid gap-3 rounded border border-slate-200 p-3 sm:grid-cols-12"
                    >
                        <div class="sm:col-span-4">
                            <p class="font-mono text-sm font-medium text-ink-900">{{ line.lot_no }}</p>
                            <p class="text-xs text-ink-500">
                                {{ [line.item_code, line.bin_code].filter(Boolean).join(' · ') || '—' }}
                            </p>
                        </div>
                        <FormField class="sm:col-span-3" label="Counted qty" :error="lineError(line, 'counted_qty')">
                            <TextInput v-model="line.counted_qty" type="number" min="0" step="any" inputmode="decimal" />
                        </FormField>
                        <FormField class="sm:col-span-5" label="Remarks" :error="lineError(line, 'remarks')">
                            <TextInput v-model="line.remarks" />
                        </FormField>
                    </div>
                </div>
            </Card>

            <template #footer>
                <FormFooter
                    :form="form"
                    :cancel-href="isEdit ? `/physical-counts/${count.id}` : '/physical-counts'"
                    :label="isEdit ? 'Save counts' : 'Open count'"
                    :summary="isEdit ? `${countedLines} of ${totalLines} counted` : null"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
