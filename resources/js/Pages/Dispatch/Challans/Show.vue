<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { date, pcs } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    challan: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
});

const issueOpen = ref(false);
const returnOpen = ref(false);

const issueForm = useForm({ to: 'issued', override_reason: '' });
const transitForm = useForm({ to: 'in_transit', courier_name: '', tracking_no: '' });
const deliverForm = useForm({ to: 'delivered', pod_ref: '' });
const returnForm = useForm({ to: 'returned', return_reason: '' });

function post(form, close) {
    form.post(`/delivery-challans/${props.challan.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => close?.(),
    });
}

function overBand(row) {
    if (!row.ordered_qty) return false;
    const after = Number(row.delivered_qty) + Number(row.qty);

    return after > Number(row.ordered_qty) * (1 + Number(row.over_tolerance_pct) / 100);
}

const columns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'product_code', label: 'Product' },
    { key: 'lot_no', label: 'Lot' },
    { key: 'qty', label: 'Quantity', align: 'right' },
    { key: 'cartons', label: 'Cartons', align: 'right' },
    { key: 'band', label: 'BR-44' },
];
</script>

<template>
    <AppLayout>
        <Head :title="challan.number ?? 'Delivery challan'" />

        <template #title>{{ challan.number ?? '(draft challan)' }}</template>
        <template #subtitle>
            <Link v-if="challan.packing_list" :href="`/packing-lists/${challan.packing_list.id}`" class="hover:underline">
                {{ challan.packing_list.number }}
            </Link>
            · {{ date(challan.challan_date) }} · {{ challan.mode }}
        </template>

        <template #actions>
            <Badge :status="challan.status" />
            <Button v-if="availableTransitions.includes('issued')" size="sm" variant="primary" @click="issueOpen = true">Issue</Button>
            <Button v-if="availableTransitions.includes('in_transit')" size="sm" @click="post(transitForm)">In transit</Button>
            <Button v-if="availableTransitions.includes('delivered')" size="sm" variant="primary" @click="post(deliverForm)">Delivered</Button>
            <Button v-if="availableTransitions.includes('returned')" size="sm" variant="danger" @click="returnOpen = true">Return</Button>
            <Button
                v-if="['issued', 'in_transit', 'delivered'].includes(challan.status) && can('sales_invoice.create')"
                size="sm"
                @click="router.post('/invoices', { delivery_challan_id: challan.id })"
            >
                Create invoice
            </Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="post(useForm({ to: 'cancelled' }))">Cancel</Button>
        </template>

        <div class="space-y-4">
            <Card title="Lines" rule="D3 · BR-44" :padded="false">
                <DataTable :columns="columns" :rows="lines" row-key="id" empty="No lines." dense>
                    <template #cell:qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:band="{ row }">
                        <Badge v-if="overBand(row)" tone="danger" label="over band" />
                        <span v-else class="text-xs text-ink-400">within</span>
                    </template>
                </DataTable>
            </Card>

            <Card v-if="challan.packing_list?.cert_claim_scheme" title="Certification">
                <p class="text-sm text-ink-700">
                    Ships under <b>{{ challan.packing_list.cert_claim_scheme }}</b> at
                    {{ challan.packing_list.cert_claim_pct }}% — a certificate must be valid on the
                    challan date (BR-43), and issuing writes the CoC output side (BR-42).
                </p>
            </Card>

            <Card v-if="challan.remarks" title="Remarks">
                <p class="whitespace-pre-line text-sm text-ink-700">{{ challan.remarks }}</p>
            </Card>
        </div>

        <Modal v-model:open="issueOpen" title="Issue this challan" subtitle="This is the stock movement." width="max-w-lg">
            <p class="text-sm text-ink-700">
                Issuing posts one <code>dispatch</code> ledger movement per line, moves the order's
                delivered quantity, and — for certified goods — writes the chain-of-custody output.
                It is undone only by a documented return.
            </p>
            <FormField
                v-if="lines.some(overBand)"
                label="Override reason (required — a line is over the BR-44 band)"
                :error="issueForm.errors.override_reason"
                class="mt-3"
            >
                <textarea v-model="issueForm.override_reason" rows="2" class="w-full rounded-md border-slate-300 text-sm" />
            </FormField>
            <template #footer>
                <Button @click="issueOpen = false">Keep as draft</Button>
                <Button variant="primary" :disabled="issueForm.processing" @click="post(issueForm, () => (issueOpen = false))">
                    Issue and post dispatch
                </Button>
            </template>
        </Modal>

        <Modal v-model:open="returnOpen" title="Return this delivery" subtitle="Reverses the dispatch and restores stock." width="max-w-lg">
            <FormField label="Failure reason" :error="returnForm.errors.return_reason" required>
                <textarea v-model="returnForm.return_reason" rows="2" class="w-full rounded-md border-slate-300 text-sm" />
            </FormField>
            <template #footer>
                <Button @click="returnOpen = false">Back</Button>
                <Button variant="danger" :disabled="returnForm.processing" @click="post(returnForm, () => (returnOpen = false))">
                    Confirm return
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
