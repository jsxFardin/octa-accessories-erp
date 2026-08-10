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
import FormPage from '@/Components/Ui/FormPage.vue';

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

const columns = [
    { key: 'operation', label: 'Operation', width: '16rem' },
    { key: 'machine_group_id', label: 'Machine group', width: '11rem' },
    { key: 'std_rate_per_hour', label: 'Rate / hour', width: '8rem', align: 'right' },
    { key: 'setup', label: 'Make-ready', width: '11rem' },
    { key: 'wastage_pct', label: 'Wastage %', width: '7rem', align: 'right' },
    { key: 'manning_level', label: 'Manning', width: '7rem', align: 'right' },
    { key: 'flags', label: 'Flags', width: '13rem' },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${routing.code}` : 'New routing'" />

        <template #title>{{ isEdit ? `Routing ${routing.code}` : 'New routing' }}</template>
        <template #subtitle>Operations execute in the order listed here (J2)</template>

        <FormPage wide>


            <form class="space-y-4" @submit.prevent="submit">
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
                                <input v-model="form.is_default" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                Default for this type
                            </label>
                            <label class="flex items-center gap-2 text-sm text-ink-700">
                                <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                Active
                            </label>
                        </div>
                    </div>
                </Card>

                <Card title="Operations" rule="BR-8 · BR-16" :padded="false">
                    <div class="p-3">
                        <LineItemsTable
                            :columns="columns"
                            :lines="form.operations"
                            :errors="form.errors"
                            add-label="Add operation"
                            empty="A routing needs at least one operation."
                            @add="addOperation"
                            @remove="removeOperation"
                        >
                            <template #cell:operation="{ line }">
                                <div class="space-y-1">
                                    <TextInput cell v-model="line.code" placeholder="weave" />
                                    <TextInput cell v-model="line.name" placeholder="Weaving" />
                                </div>
                            </template>

                            <template #cell:machine_group_id="{ line }">
                                <SelectInput v-model="line.machine_group_id" :options="machineGroups" value-key="id" label-key="name" />
                            </template>

                            <template #cell:std_rate_per_hour="{ line }">
                                <TextInput cell v-model="line.std_rate_per_hour" type="number" step="0.000001" numeric />
                            </template>

                            <template #cell:setup="{ line }">
                                <div class="space-y-1">
                                    <TextInput cell v-model="line.setup_minutes" type="number" step="0.01" placeholder="Setup minutes" numeric />
                                    <TextInput cell v-model="line.setup_qty" type="number" step="0.000001" placeholder="Make-ready metres" numeric />
                                </div>
                            </template>

                            <template #cell:wastage_pct="{ line }">
                                <TextInput cell v-model="line.wastage_pct" type="number" step="0.01" numeric />
                            </template>

                            <template #cell:manning_level="{ line }">
                                <TextInput cell v-model="line.manning_level" type="number" step="0.01" numeric />
                            </template>

                            <template #cell:flags="{ line }">
                                <div class="space-y-1 text-xs">
                                    <!-- The flag that decides whether this step's wastage counts at all. -->
                                    <label class="flex items-center gap-1.5 text-ink-700">
                                        <input v-model="line.consumes_web" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        Consumes web
                                    </label>
                                    <label class="flex items-center gap-1.5 text-ink-700">
                                        <input v-model="line.allow_parallel" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        May run in parallel
                                    </label>
                                    <label class="flex items-center gap-1.5 text-ink-700">
                                        <input v-model="line.requires_qc" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        Requires QC
                                    </label>
                                </div>
                            </template>

                            <template #footer>
                                <tr>
                                    <td colspan="4" class="px-3 py-2 text-right text-xs text-ink-700">
                                        Additive wastage across web-consuming operations
                                    </td>
                                    <td class="px-2 py-2 text-right text-sm font-semibold tnum text-ink-900">
                                        {{ totalWastage.toFixed(2) }}%
                                    </td>
                                    <td colspan="3" class="px-2 py-2 text-xs text-ink-500">
                                        + {{ totalSetup }} m make-ready
                                    </td>
                                </tr>
                            </template>
                        </LineItemsTable>

                        <p v-if="form.errors.operations" class="mt-2 text-xs text-rose-600">{{ form.errors.operations }}</p>

                        <p class="mt-2 text-xs text-ink-500">
                            Manning level is operators per machine — a loom watched one-in-four is 0.25,
                            a screen table needing two people is 2.0 (BR-17).
                        </p>
                    </div>
                </Card>
            </form>
        

            <FormFooter
                :form="form"
                cancel-href="/routings"
                :label="isEdit ? 'Save changes' : 'Create routing'"
                @save="submit"
            />
        </FormPage>
    </AppLayout>
</template>
