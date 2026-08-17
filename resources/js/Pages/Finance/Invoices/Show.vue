<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import { date, money, pcs, ratePerM } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    invoice: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    allocations: { type: Array, default: () => [] },
    creditNotes: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
});

const creditForm = useForm({
    sales_invoice_id: props.invoice.id,
    reason: 'quality_claim',
    amount: null,
});

function transition(to) {
    router.post(`/invoices/${props.invoice.id}/transition`, { to }, { preserveScroll: true });
}

const columns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'product_code', label: 'Product' },
    { key: 'description', label: 'Description' },
    { key: 'qty', label: 'Delivered qty', align: 'right' },
    { key: 'rate_per_m', label: 'Rate /M', align: 'right' },
    { key: 'amount', label: 'Amount', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head :title="invoice.number ?? 'Invoice'" />

        <template #title>{{ invoice.number ?? '(draft invoice)' }}</template>
        <template #subtitle>
            {{ invoice.customer?.name }} · {{ date(invoice.invoice_date) }} · due {{ date(invoice.due_date) }}
        </template>

        <template #actions>
            <Badge :status="invoice.status" />
            <Button v-if="availableTransitions.includes('issued')" size="sm" variant="primary" @click="transition('issued')">
                Issue
            </Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="transition('cancelled')">
                Cancel
            </Button>
        </template>

        <div class="space-y-4">
            <Card title="Lines" rule="FN-1 · billed = delivered" :padded="false">
                <DataTable :columns="columns" :rows="lines" row-key="id" empty="No lines." dense>
                    <template #cell:qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:rate_per_m="{ value }">{{ ratePerM(value) }}</template>
                    <template #cell:amount="{ value }">{{ money(value) }}</template>
                </DataTable>
            </Card>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card title="Totals" rule="total = received + credited + outstanding">
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div><dt class="text-xs text-ink-500">Subtotal</dt><dd class="font-medium tnum">{{ money(invoice.subtotal) }}</dd></div>
                        <div><dt class="text-xs text-ink-500">Total</dt><dd class="font-medium tnum">{{ money(invoice.total) }}</dd></div>
                        <div><dt class="text-xs text-ink-500">Received</dt><dd class="font-medium tnum text-emerald-700">{{ money(invoice.received_amount) }}</dd></div>
                        <div><dt class="text-xs text-ink-500">Credited</dt><dd class="font-medium tnum text-amber-700">{{ money(invoice.credited ?? 0) }}</dd></div>
                        <div><dt class="text-xs text-ink-500">Outstanding</dt><dd class="font-medium tnum" :class="invoice.outstanding > 0 ? 'text-rose-600' : ''">{{ money(invoice.outstanding) }}</dd></div>
                    </dl>

                    <form
                        v-if="can('credit_note.create') && ['issued', 'partially_paid', 'overdue'].includes(invoice.status)"
                        class="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3"
                        @submit.prevent="creditForm.post('/credit-notes')"
                    >
                        <FormField label="Credit reason" class="w-40">
                            <select v-model="creditForm.reason" class="w-full rounded-md border-slate-300 text-xs">
                                <option v-for="reason in ['quality_claim','short_delivery','rate_difference','discount','other']" :key="reason" :value="reason">{{ reason }}</option>
                            </select>
                        </FormField>
                        <FormField label="Amount" class="w-32">
                            <input v-model="creditForm.amount" type="number" min="0.01" step="any" class="w-full rounded-md border-slate-300 text-xs" :placeholder="`≤ ${invoice.outstanding}`" />
                        </FormField>
                        <Button type="submit" size="xs" :disabled="creditForm.processing">Draft credit note</Button>
                    </form>
                </Card>

                <Card v-if="creditNotes.length" title="Credit notes" :padded="false">
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="note in creditNotes" :key="note.id" class="flex items-center justify-between gap-2 px-4 py-2">
                            <Link :href="`/credit-notes/${note.id}`" class="font-medium text-brand-700">{{ note.number ?? '(draft)' }}</Link>
                            <span class="text-xs text-ink-500">{{ note.reason }}</span>
                            <span class="tnum">{{ money(note.amount) }}</span>
                            <Badge :status="note.status" />
                        </li>
                    </ul>
                </Card>

                <Card title="Receipts against this invoice" :padded="false">
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="(allocation, index) in allocations" :key="index" class="flex items-center justify-between px-4 py-2">
                            <span class="font-medium">{{ allocation.number }}</span>
                            <span class="text-xs text-ink-500">{{ date(allocation.receipt_date) }} · {{ allocation.method }}</span>
                            <span class="tnum">{{ money(allocation.amount) }}</span>
                        </li>
                        <li v-if="!allocations.length" class="px-4 py-6 text-center text-sm text-ink-500">
                            Nothing received yet.
                        </li>
                    </ul>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
