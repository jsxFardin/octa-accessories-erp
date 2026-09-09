<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import { qty } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    schemes: { type: Array, default: () => [] },
});

const columns = [
    { key: 'scheme', label: 'Scheme' },
    { key: 'period', label: 'Period' },
    { key: 'certified_received_qty', label: 'Received', align: 'right' },
    { key: 'certified_input_qty', label: 'Consumed', align: 'right' },
    { key: 'certified_output_qty', label: 'Shipped', align: 'right' },
    { key: 'conversion_factor', label: 'Conversion', align: 'right' },
    { key: 'max_conversion_factor', label: 'Max', align: 'right' },
    { key: 'flagged', label: 'Audit' },
    { key: 'close', label: '', align: 'right', width: '7rem' },
];

/*
 * CP-5 AC3 / C3 — closing a period locks its transactions.
 *
 * `is_locked` was rendered on the compliance index and set by nothing: the column, the
 * `coc.lock_period` permission and the invariant all existed, and no route did. A chain of
 * custody that can still take rows for a month an auditor has already been shown is not one.
 */
const mayClose = can('coc.lock_period');
const closing = ref(null);
const closeForm = useForm({ scheme: '', period_year: null, period_month: null, acknowledge_breach: false });

function askClose(row) {
    closing.value = row;
    closeForm.defaults({
        scheme: row.scheme,
        period_year: row.period_year,
        period_month: row.period_month,
        acknowledge_breach: false,
    });
    closeForm.reset();
    closeForm.clearErrors();
}

function submitClose() {
    closeForm.post('/compliance/close-period', {
        preserveScroll: true,
        onSuccess: () => { closing.value = null; },
    });
}
</script>

<template>
    <AppLayout>
        <Head title="Chain of custody reconciliation" />

        <template #title>Chain of custody reconciliation</template>
        <template #subtitle>
            Certified input against certified output, per scheme per period — the exact figure a GRS or FSC auditor asks for
        </template>

        <template #actions>
            <div class="w-36">
                <SelectInput
                    :model-value="filters.scheme ?? ''"
                    placeholder="All schemes"
                    :options="schemes.map((scheme) => ({ value: scheme, label: scheme.replace('_', ' ') }))"
                    @update:model-value="router.get('/compliance/reconciliation', { ...filters, scheme: $event || undefined }, { preserveState: true, replace: true })"
                />
            </div>
        </template>

        <Card :padded="false">
            <DataTable
                :columns="columns"
                :rows="rows"
                row-key="period"
                empty="No chain-of-custody transactions recorded yet. Certified input enters the system on a GRN line."
            >
                <template #cell:scheme="{ value }">
                    <span class="font-medium text-ink-900">{{ value.replace('_', ' ') }}</span>
                </template>
                <template #cell:certified_received_qty="{ value }">{{ qty(value) }}</template>
                <!-- The balance is struck against consumption; receipts are context. -->
                <template #cell:certified_input_qty="{ value }">{{ qty(value) }}</template>
                <template #cell:certified_output_qty="{ value }">{{ qty(value) }}</template>
                <template #cell:conversion_factor="{ value }">{{ Number(value).toFixed(4) }}</template>
                <template #cell:flagged="{ value }">
                    <!-- Flagged means more certified goods left than came in: the condition an auditor tests -->
                    <Badge v-if="value" tone="danger" label="Exceeds input" />
                    <Badge v-else tone="success" label="Within input" />
                </template>
                <template #cell:close="{ row }">
                    <Badge v-if="row.is_closed" tone="neutral" label="Closed" />
                    <Button v-else-if="mayClose" size="sm" variant="secondary" @click="askClose(row)">Close</Button>
                </template>
            </DataTable>
        </Card>

        <Modal
            :open="closing !== null"
            title="Close this period"
            subtitle="C3 — the transactions in it are locked, and no further certified movement may be booked into it."
            @update:open="closing = null"
        >
            <div v-if="closing" class="space-y-3">
                <dl class="grid grid-cols-3 gap-2 rounded-md bg-slate-50 px-3 py-2 text-sm">
                    <div>
                        <dt class="text-xs text-ink-500">Scheme</dt>
                        <dd class="font-medium">{{ closing.scheme.replace('_', ' ') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Period</dt>
                        <dd class="font-medium tnum">{{ closing.period }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Conversion</dt>
                        <dd class="font-medium tnum" :class="closing.flagged ? 'text-rose-700' : ''">
                            {{ Number(closing.conversion_factor).toFixed(4) }}
                        </dd>
                    </div>
                </dl>

                <p class="text-sm text-ink-700">
                    A receipt, an issue or a dispatch dated into this month will be refused once it is closed.
                    Reopening a certified period is a compliance decision, not a data entry one.
                </p>

                <!--
                    Closing a period whose factor is impossible signs off the number an auditor
                    is most likely to challenge. Allowed — the figure may be right and the
                    ceiling wrong — but said out loud.
                -->
                <label v-if="closing.flagged" class="flex items-start gap-2 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    <input v-model="closeForm.acknowledge_breach" type="checkbox" class="mt-0.5">
                    <span>
                        This period converts above its ceiling. I have reviewed the transactions behind it
                        and the figure is correct.
                    </span>
                </label>
            </div>

            <template #footer>
                <Button @click="closing = null">Cancel</Button>
                <Button
                    variant="danger"
                    :loading="closeForm.processing"
                    :disabled="closing?.flagged && !closeForm.acknowledge_breach"
                    @click="submitClose"
                >
                    Close period
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
