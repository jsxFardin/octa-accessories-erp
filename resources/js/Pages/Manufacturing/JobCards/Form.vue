<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import UnsavedBar from '@/Components/Ui/UnsavedBar.vue';
import { date, pcs } from '@/plugins/formatting';

const props = defineProps({
    orderLines: { type: Array, default: () => [] },
    units: { type: Array, default: () => [] },
});

const form = useForm({
    sales_order_line_id: '',
    factory_unit_id: props.units[0]?.id ?? '',
    planned_qty: '',
    colourway: '',
    due_date: '',
    priority: 50,
});

const selectedLine = computed(() =>
    props.orderLines.find((line) => line.id === Number(form.sales_order_line_id)) ?? null,
);

/** What is left to make on the chosen line — the sensible default for the card. */
const outstanding = computed(() => {
    if (!selectedLine.value) return 0;

    return Math.max(0, Number(selectedLine.value.ordered_qty) - Number(selectedLine.value.produced_qty));
});

function pickLine(id) {
    form.sales_order_line_id = id;

    const line = props.orderLines.find((candidate) => candidate.id === Number(id));

    if (line) {
        form.planned_qty = Math.max(0, Number(line.ordered_qty) - Number(line.produced_qty));
        form.due_date = form.due_date || line.promised_date || '';
    }
}

function submit() {
    form.post('/job-cards');
}
</script>

<template>
    <AppLayout>
        <Head title="New job card" />

        <template #title>New job card</template>
        <template #subtitle>From a confirmed sales order line</template>

        <template #actions>
            <Button href="/job-cards">Cancel</Button>
            <Button
                variant="primary"
                :loading="form.processing"
                :disabled="!form.sales_order_line_id"
                @click="submit"
            >
                Create draft
            </Button>
        </template>

        <div class="grid max-w-6xl gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2" title="Order line to produce" :padded="false">
                <div class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
                    <label
                        v-for="line in orderLines"
                        :key="line.id"
                        class="flex cursor-pointer items-start gap-3 px-3 py-2.5 transition hover:bg-slate-50"
                        :class="Number(form.sales_order_line_id) === line.id && 'bg-brand-50'"
                    >
                        <input
                            type="radio"
                            class="mt-1 border-slate-300 text-brand-600 focus:ring-brand-500"
                            :checked="Number(form.sales_order_line_id) === line.id"
                            @change="pickLine(line.id)"
                        >

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 text-sm">
                                <span class="font-medium text-ink-900">{{ line.so_number }}</span>
                                <span class="text-ink-500">line {{ line.line_no }}</span>
                                <span class="font-medium text-ink-800">{{ line.product_code }}</span>
                            </div>
                            <p class="truncate text-xs text-ink-500">
                                {{ line.customer_name }} — {{ line.product_name }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right text-xs">
                            <p class="tnum text-ink-800">
                                {{ pcs(Number(line.ordered_qty) - Number(line.produced_qty)) }} left
                            </p>
                            <p class="tnum text-ink-500">of {{ pcs(line.ordered_qty) }}</p>
                            <p v-if="line.promised_date" class="text-ink-400">due {{ date(line.promised_date) }}</p>
                        </div>
                    </label>

                    <p v-if="orderLines.length === 0" class="px-3 py-10 text-center text-sm text-ink-500">
                        No confirmed order line has quantity left to produce.
                    </p>
                </div>

                <p v-if="form.errors.sales_order_line_id" class="border-t border-slate-200 px-3 py-2 text-xs text-rose-600">
                    {{ form.errors.sales_order_line_id }}
                </p>
            </Card>

            <div class="space-y-4">
                <Card title="Card">
                    <div class="space-y-3">
                        <FormField label="Factory unit" :error="form.errors.factory_unit_id" required>
                            <SelectInput
                                v-model="form.factory_unit_id"
                                :placeholder="null"
                                :options="units"
                                value-key="id"
                                label-key="name"
                            />
                        </FormField>

                        <FormField
                            label="Planned quantity"
                            :hint="selectedLine ? `${pcs(outstanding)} outstanding on this line` : null"
                            :error="form.errors.planned_qty"
                            required
                        >
                            <TextInput v-model="form.planned_qty" type="number" numeric min="1" />
                        </FormField>

                        <FormField
                            label="Colourway"
                            rule="BR-28"
                            hint="One card per colourway — a loom cannot weave two at once."
                            :error="form.errors.colourway"
                        >
                            <TextInput v-model="form.colourway" placeholder="White / Navy" />
                        </FormField>

                        <FormField label="Due date" :error="form.errors.due_date">
                            <DateInput v-model="form.due_date" />
                        </FormField>

                        <FormField label="Priority" hint="1 is most urgent; 50 is routine." :error="form.errors.priority">
                            <TextInput v-model="form.priority" type="number" numeric min="1" max="99" />
                        </FormField>
                    </div>
                </Card>

                <!--
                    Gate 1 is structural: `job_cards.artwork_version_id` is NOT NULL, so the
                    controller resolves the approved version and refuses outright if there
                    isn't one. Nothing on this form can bypass it.
                -->
                <Card title="What happens on save" rule="Gate 1 · J1">
                    <ul class="space-y-2 text-xs text-ink-700">
                        <li class="flex gap-2">
                            <Badge tone="info" label="1" />
                            <span>The product's <strong>approved artwork version</strong> is resolved and bound. No approved version, no card.</span>
                        </li>
                        <li class="flex gap-2">
                            <Badge tone="info" label="2" />
                            <span>The consumption plan is computed and <strong>snapshotted</strong> — gross metres, ends, labels per metre (BR-4 … BR-13).</span>
                        </li>
                        <li class="flex gap-2">
                            <Badge tone="info" label="3" />
                            <span>One operation per routing step is scheduled with its planned minutes (BR-27).</span>
                        </li>
                        <li class="flex gap-2">
                            <Badge tone="neutral" label="4" />
                            <span>The card is a <strong>draft</strong>. Planning numbers it; the J1 gate governs release.</span>
                        </li>
                    </ul>
                </Card>
            </div>
        </div>
        <UnsavedBar :form="form" @save="submit" />

    </AppLayout>
</template>
