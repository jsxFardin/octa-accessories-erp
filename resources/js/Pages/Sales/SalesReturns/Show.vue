<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { date, money, pcs } from '@/plugins/formatting';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    salesReturn: { type: Object, required: true },
    invoice: { type: Object, default: null },
    lines: { type: Array, default: () => [] },
    creditNotes: { type: Array, default: () => [] },
    movements: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
});

const confirmTransition = useTransitionConfirm();

async function transition(to) {
    if (!(await confirmTransition(to, props.salesReturn.number ?? 'this return'))) return;

    router.post(`/sales-returns/${props.salesReturn.id}/transition`, { to }, { preserveScroll: true });
}

const cancelOpen = ref(false);
const cancelForm = useForm({ to: 'cancelled', reason: '' });

const lineColumns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'product_code', label: 'Product' },
    { key: 'invoice_line_no', label: 'Invoice line', align: 'center' },
    { key: 'lot_no', label: 'Lot' },
    { key: 'qty', label: 'Returned', align: 'right' },
    { key: 'rate_per_m', label: 'Rate / 1,000', align: 'right' },
];
</script>

<template>
    <AppLayout :crumb="salesReturn.number ?? 'Draft return'">
        <Head :title="salesReturn.number ?? 'Customer return'" />

        <template #title>{{ salesReturn.number ?? '(unnumbered)' }}</template>
        <template #subtitle>
            {{ salesReturn.customer }} · returned {{ date(salesReturn.returned_on) }} · back into {{ salesReturn.warehouse }}
        </template>

        <template #actions>
            <Badge :status="salesReturn.status" />

            <Button v-if="availableTransitions.includes('approved')" size="sm" variant="success" @click="transition('approved')">
                Approve
            </Button>
            <Button v-if="availableTransitions.includes('posted')" size="sm" variant="primary" @click="transition('posted')">
                Post — put the goods back
            </Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="cancelOpen = true">
                Cancel
            </Button>
        </template>

        <div class="space-y-4">
            <!--
                The invoice panel, first and unmissable. Everything about this screen has to
                leave someone in no doubt that the invoice is untouched: it shows its own
                status and its own money, and nothing here changes either.
            -->
            <Card v-if="invoice" title="Billed on" subtitle="Not reopened, not amended — the document these goods were sold on">
                <dl class="grid gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-ink-500">Invoice</dt>
                        <dd class="font-medium">
                            <Link :href="`/invoices/${invoice.id}`" class="doc-link-quiet">{{ invoice.number }}</Link>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Status</dt>
                        <dd><Badge :status="invoice.status" /></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Invoice total</dt>
                        <dd class="font-medium tnum">{{ money(invoice.total) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Received</dt>
                        <dd class="font-medium tnum">{{ money(invoice.received_amount) }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs leading-relaxed text-ink-500">
                    A return never changes what was billed or what was paid. The value of the goods comes
                    back as a credit note, which can be applied to another open invoice or refunded.
                </p>
            </Card>

            <Card title="Lines" :padded="false" subtitle="Each line names the invoice line it came off and the lot it shipped on">
                <DataTable :columns="lineColumns" :rows="lines" row-key="id" empty="No lines." dense>
                    <template #cell:qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:rate_per_m="{ value }">{{ money(value) }}</template>
                    <template #cell:lot_no="{ row, value }">
                        <span v-if="value" class="font-mono text-xs">{{ value }}</span>
                        <span v-else class="text-xs text-ink-400">not traced</span>
                        <Badge v-if="row.lot_status" :status="row.lot_status" />
                    </template>
                </DataTable>
            </Card>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card title="Stock put back" subtitle="Posted against the lot the goods left on, at that lot's cost">
                    <ul v-if="movements.length" class="divide-y divide-slate-100 text-sm">
                        <li v-for="movement in movements" :key="movement.id" class="flex items-center justify-between py-2">
                            <span class="text-ink-600">{{ date(movement.occurred_at) }}</span>
                            <span class="tnum">{{ pcs(movement.qty) }} @ {{ money(movement.unit_cost) }}</span>
                            <span class="font-medium tnum">{{ money(movement.value) }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-ink-500">
                        Nothing yet — stock moves when the return is posted, and lands in quarantine until
                        someone has looked at it.
                    </p>
                </Card>

                <Card title="Credit raised" subtitle="Drafted automatically when the return is posted">
                    <ul v-if="creditNotes.length" class="divide-y divide-slate-100 text-sm">
                        <li v-for="note in creditNotes" :key="note.id" class="flex items-center justify-between py-2">
                            <Link :href="`/credit-notes/${note.id}`" class="doc-link-quiet">{{ note.number ?? '(draft)' }}</Link>
                            <span class="tnum">{{ money(note.amount) }}</span>
                            <Badge :status="note.status" />
                        </li>
                    </ul>
                    <p v-else class="text-sm text-ink-500">
                        None yet. Posting the return drafts a credit note for the value of what came back,
                        for accounts to approve.
                    </p>
                </Card>
            </div>

            <Card v-if="salesReturn.reason" title="Reason">
                <p class="text-sm whitespace-pre-line text-ink-700">{{ salesReturn.reason }}</p>
            </Card>
        </div>

        <Modal v-model:open="cancelOpen" title="Cancel this return" subtitle="Nothing has been posted, so nothing is unwound.">
            <FormField label="Reason" :error="cancelForm.errors.reason" required>
                <textarea v-model="cancelForm.reason" rows="2" class="form-textarea" />
            </FormField>
            <template #footer="{ close }">
                <Button @click="close">Keep it</Button>
                <Button
                    variant="danger"
                    :disabled="!cancelForm.reason"
                    :loading="cancelForm.processing"
                    @click="cancelForm.post(`/sales-returns/${salesReturn.id}/transition`, { onSuccess: () => (cancelOpen = false) })"
                >
                    Cancel the return
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
