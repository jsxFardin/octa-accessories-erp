<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { datetime } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ months: { type: Array, default: () => [] } });

const columns = [
    { key: 'label', label: 'Month' },
    { key: 'is_closed', label: 'Status' },
    { key: 'closed_at', label: 'Closed' },
    { key: 'reopened_at', label: 'Reopened' },
    { key: 'note', label: 'Note', wrap: true },
    { key: 'action', label: '', align: 'right', width: '8rem' },
];

/*
 * Closing asks once and names what stops. Reopening asks for a reason, because the reason
 * is what the audit log carries and what the auditor reads.
 */
const acting = ref(null); // { month, mode: 'close' | 'reopen' }
const form = useForm({ year: null, month: null, note: '' });

function ask(month, mode) {
    acting.value = { month, mode };
    form.defaults({ year: month.year, month: month.month, note: '' });
    form.reset();
    form.clearErrors();
}

function submit() {
    form.post(`/admin/accounting-periods/${acting.value.mode}`, {
        preserveScroll: true,
        onSuccess: () => { acting.value = null; },
    });
}
</script>

<template>
    <AppLayout>
        <Head title="Accounting periods" />

        <template #title>Accounting periods</template>
        <template #subtitle>
            A closed month takes no stock movement, invoice, bill, receipt or payment dated into it. Reopening is recorded with a reason.
        </template>

        <Card :padded="false">
            <DataTable :columns="columns" :rows="months" row-key="key" empty="No months yet.">
                <template #cell:label="{ row, value }">
                    <span class="font-medium text-ink-900">{{ value }}</span>
                    <span v-if="row.is_current" class="ml-2 text-xs text-ink-500">current</span>
                </template>
                <template #cell:is_closed="{ value }">
                    <Badge v-if="value" tone="neutral" label="Closed" />
                    <Badge v-else tone="success" label="Open" />
                </template>
                <template #cell:closed_at="{ row }">
                    <template v-if="row.closed_at">{{ datetime(row.closed_at) }} <span class="text-ink-500">by {{ row.closed_by }}</span></template>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:reopened_at="{ row }">
                    <template v-if="row.reopened_at">{{ datetime(row.reopened_at) }} <span class="text-ink-500">by {{ row.reopened_by }}</span></template>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:note="{ value }">
                    <span v-if="value">{{ value }}</span>
                    <span v-else class="text-ink-400">—</span>
                </template>
                <template #cell:action="{ row }">
                    <Button v-if="!row.is_closed && can('accounting_period.close')" size="sm" variant="secondary" @click="ask(row, 'close')">Close</Button>
                    <Button v-else-if="row.is_closed && can('accounting_period.reopen')" size="sm" @click="ask(row, 'reopen')">Reopen</Button>
                </template>
            </DataTable>
        </Card>

        <Modal
            :open="acting !== null"
            :title="acting?.mode === 'close' ? `Close ${acting?.month.label}` : `Reopen ${acting?.month.label}`"
            :subtitle="acting?.mode === 'close'
                ? 'No stock movement, invoice, bill, receipt or payment can be dated into it afterwards.'
                : 'Entries can be dated into it again. The reopening and your reason go on the audit log.'"
            @update:open="acting = null"
        >
            <div class="space-y-3">
                <p v-if="form.errors.period" class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ form.errors.period }}</p>
                <FormField :label="acting?.mode === 'close' ? 'Note' : 'Reason'" :error="form.errors.note" :required="acting?.mode === 'reopen'">
                    <input v-model="form.note" type="text" class="form-input" maxlength="255" :placeholder="acting?.mode === 'close' ? 'Optional' : 'Why this month has to take an entry again'">
                </FormField>
            </div>

            <template #footer>
                <Button @click="acting = null">Cancel</Button>
                <Button :variant="acting?.mode === 'close' ? 'danger' : 'primary'" :loading="form.processing" @click="submit">
                    {{ acting?.mode === 'close' ? 'Close month' : 'Reopen month' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
