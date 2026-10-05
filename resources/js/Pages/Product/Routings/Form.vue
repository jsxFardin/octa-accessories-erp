<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import LineItemsTable from '@/Components/Ui/LineItemsTable.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import { pct, qty } from '@/plugins/formatting';

const props = defineProps({
    routing: { type: Object, default: null },
    machineGroups: { type: Array, default: () => [] },
    productTypes: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.routing));

function blankOperation() {
    return {
        code: '',
        name: '',
        machine_group_id: '',
        std_rate_per_hour: '',
        setup_minutes: 0,
        setup_qty: 0,
        wastage_pct: 0,
        manning_level: 1,
        consumes_web: true,
        allow_parallel: false,
        requires_qc: false,
    };
}

const form = useForm({
    code: props.routing?.code ?? '',
    name: props.routing?.name ?? '',
    product_type: props.routing?.product_type ?? '',
    max_lot_size: props.routing?.max_lot_size ?? '',
    is_default: props.routing?.is_default ?? false,
    is_active: props.routing?.is_active ?? true,
    operations: props.routing?.operations?.length
        ? props.routing.operations.map((operation) => ({ ...operation }))
        : [blankOperation()],
});

function addOperation() {
    form.operations = [...form.operations, blankOperation()];
}

function removeOperation(index) {
    form.operations = form.operations.filter((_, i) => i !== index);
}

/*
 * Steps run in the order listed, and the order could only be changed by retyping rows: a step
 * forgotten in the middle meant re-entering everything below it.
 */
function moveOperation(index, by) {
    const to = index + by;

    if (to < 0 || to >= form.operations.length) return;

    const operations = [...form.operations];
    operations.splice(to, 0, operations.splice(index, 1)[0]);
    form.operations = operations;
}

/** Metres for a step that runs the web, pieces for one that handles finished labels. */
const rateUnit = (operation) => (operation.consumes_web ? 'm per hour' : 'pcs per hour');

/**
 * BR-8 — wastage is additive across the operations that consume the web, and only those.
 * Packing and QC do not eat ribbon, so they must not inflate the total.
 */
const totalWastage = computed(() =>
    form.operations
        .filter((operation) => operation.consumes_web)
        .reduce((sum, operation) => sum + (Number(operation.wastage_pct) || 0), 0),
);

const totalSetup = computed(() =>
    form.operations
        .filter((operation) => operation.consumes_web)
        .reduce((sum, operation) => sum + (Number(operation.setup_qty) || 0), 0),
);

function submit() {
    isEdit.value ? form.put(`/routings/${props.routing.id}`) : form.post('/routings');
}

/*
 * One column per figure. Code and name, and setup minutes and make-ready metres, used to be
 * stacked two to a cell under one heading — told apart only by placeholders that vanish on the
 * first keystroke, and read out identically by a screen reader. Minutes and metres got swapped.
 */
const columns = [
    { key: 'order', label: 'Move', width: '4.5rem' },
    { key: 'code', label: 'Code', width: '7rem', required: true },
    { key: 'name', label: 'Name', width: '11rem', required: true },
    { key: 'machine_group_id', label: 'Machine group', width: '11rem' },
    { key: 'std_rate_per_hour', label: 'Rate per hour', width: '9rem', align: 'right' },
    { key: 'setup_minutes', label: 'Setup (minutes)', width: '7rem', align: 'right' },
    { key: 'setup_qty', label: 'Make-ready (m)', width: '7rem', align: 'right' },
    { key: 'wastage_pct', label: 'Wastage %', width: '6.5rem', align: 'right' },
    { key: 'manning_level', label: 'People', width: '6rem', align: 'right' },
    { key: 'flags', label: 'Options', width: '12rem', errorKeys: ['requires_qc', 'allow_parallel', 'consumes_web'] },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${routing.code}` : 'New routing'" />

        <template #title>{{ isEdit ? `Routing ${routing.code}` : 'New routing' }}</template>
        <template #subtitle>The steps a product of this type goes through, in order</template>

        <FormLayout @submit="submit">

            <Card title="Routing">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <FormField label="Code" :error="form.errors.code" required>
                        <TextInput v-model="form.code" placeholder="RT-WOVEN-2" />
                    </FormField>

                    <FormField label="Name" :error="form.errors.name" required>
                        <TextInput v-model="form.name" />
                    </FormField>

                    <FormField label="Product type" :error="form.errors.product_type" required>
                        <SelectInput v-model="form.product_type" placeholder="— select —" :options="productTypes" />
                    </FormField>

                    <FormField
                        label="Max lot size"
                        rule="BR-28"
                        hint="A larger order splits into several job cards."
                        :error="form.errors.max_lot_size"
                    >
                        <TextInput v-model="form.max_lot_size" type="number" numeric />
                    </FormField>

                    <div class="space-y-1 pt-5">
                        <label class="flex items-center gap-2 text-sm text-ink-700">
                            <input v-model="form.is_default" type="checkbox" class="form-checkbox">
                            Default for this type
                        </label>
                        <label class="flex items-center gap-2 text-sm text-ink-700">
                            <input v-model="form.is_active" type="checkbox" class="form-checkbox">
                            Active
                        </label>
                    </div>
                </div>
            </Card>

            <Card title="Steps" rule="BR-8 · BR-16" subtitle="A job runs these steps in the order listed." :padded="false">
                <div class="p-3">
                    <LineItemsTable
                        :columns="columns"
                        :lines="form.operations"
                        :errors="form.errors"
                        error-prefix="operations"
                        add-label="Add step"
                        empty="A routing needs at least one step."
                        @add="addOperation"
                        @remove="removeOperation"
                    >
                        <template #cell:order="{ index }">
                            <div class="flex items-center gap-1">
                                <Button
                                    size="sm" variant="ghost" :disabled="index === 0"
                                    :aria-label="`Move step ${index + 1} up`" data-move-up @click="moveOperation(index, -1)"
                                >↑</Button>
                                <Button
                                    size="sm" variant="ghost" :disabled="index === form.operations.length - 1"
                                    :aria-label="`Move step ${index + 1} down`" data-move-down @click="moveOperation(index, 1)"
                                >↓</Button>
                            </div>
                        </template>

                        <template #cell:code="{ line }">
                            <TextInput cell v-model="line.code" placeholder="weave" />
                        </template>

                        <template #cell:name="{ line }">
                            <TextInput cell v-model="line.name" placeholder="Weaving" />
                        </template>

                        <template #cell:machine_group_id="{ line }">
                            <SelectInput v-model="line.machine_group_id" :options="machineGroups" value-key="id" label-key="name" placeholder="Any" clearable />
                        </template>

                        <template #cell:std_rate_per_hour="{ line }">
                            <TextInput cell v-model="line.std_rate_per_hour" type="number" step="0.000001" numeric />
                            <!-- The unit follows the step: a loom is rated in metres, a packing table in pieces. -->
                            <p class="mt-0.5 text-right text-xs text-ink-600">{{ rateUnit(line) }}</p>
                        </template>

                        <template #cell:setup_minutes="{ line }">
                            <TextInput cell v-model="line.setup_minutes" type="number" step="0.01" min="0" numeric />
                        </template>

                        <template #cell:setup_qty="{ line }">
                            <TextInput cell v-model="line.setup_qty" type="number" step="0.000001" min="0" numeric />
                        </template>

                        <template #cell:wastage_pct="{ line }">
                            <TextInput cell v-model="line.wastage_pct" type="number" step="0.01" min="0" numeric />
                        </template>

                        <template #cell:manning_level="{ line }">
                            <TextInput cell v-model="line.manning_level" type="number" step="0.01" min="0" numeric />
                        </template>

                        <template #cell:flags="{ line, index }">
                            <div class="space-y-1 text-xs">
                                <!-- The option that decides whether this step's wastage counts at all. -->
                                <label class="flex items-center gap-1.5 text-ink-700">
                                    <input v-model="line.consumes_web" type="checkbox" class="form-checkbox" :aria-label="`Uses the web, step ${index + 1}`">
                                    Uses the web (metres)
                                </label>
                                <label class="flex items-center gap-1.5 text-ink-700">
                                    <input v-model="line.allow_parallel" type="checkbox" class="form-checkbox" :aria-label="`May run in parallel, step ${index + 1}`">
                                    May run in parallel
                                </label>
                                <label class="flex items-center gap-1.5 text-ink-700">
                                    <input v-model="line.requires_qc" type="checkbox" class="form-checkbox" :aria-label="`Needs a QC check, step ${index + 1}`">
                                    Needs a QC check
                                </label>
                            </div>
                        </template>
                    </LineItemsTable>

                    <p v-if="form.errors.operations" class="mt-2 text-xs text-rose-600">{{ form.errors.operations }}</p>

                    <p class="mt-2 text-xs text-ink-600">
                        People is operators per machine: a loom watched one-in-four is 0.25, a screen table needing two people is 2.
                        Make-ready is the metres run to set the step up before good output starts.
                    </p>
                </div>
            </Card>

            <!-- In the layout's own rail slot: it had been left inside the table, where nothing drew it. -->
            <template #rail>
                <Card title="Totals" rule="BR-8 · BR-16">
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Steps</dt>
                            <dd class="tnum text-ink-900">{{ form.operations.length }}</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Steps that use the web</dt>
                            <dd class="tnum text-ink-900">
                                {{ form.operations.filter((operation) => operation.consumes_web).length }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-2.5">
                            <dt class="text-xs text-ink-500">Total wastage</dt>
                            <dd class="text-base font-semibold tnum text-ink-900">{{ pct(totalWastage, 2) }}</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Total make-ready</dt>
                            <dd class="tnum text-ink-900">{{ qty(totalSetup, 2) }} m</dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-xs leading-relaxed text-ink-600">
                        Only steps that use the web count towards these two figures. Packing and QC
                        handle finished labels; they do not use up ribbon.
                    </p>
                </Card>
            </template>

            <template #footer>
                <FormFooter
                    :form="form"
                    cancel-href="/routings"
                    :label="isEdit ? 'Save changes' : 'Create routing'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
