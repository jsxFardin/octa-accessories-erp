<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { date, pcs, titleCase } from '@/plugins/formatting';
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
    { key: 'band', label: 'Band' },
];
</script>

<template>
    <AppLayout :crumb="challan.number ?? 'Draft challan'">
        <Head :title="challan.number ?? 'Delivery challan'" />

        <template #title>{{ challan.number ?? '(draft challan)' }}</template>
        <template #subtitle>
            <Link v-if="challan.customer" :href="`/customers/${challan.customer.id}`" class="font-medium hover:underline">
                {{ challan.customer.name }}
            </Link>
            <span v-else class="text-rose-600">No customer</span>
            ·
            <Link v-if="challan.packing_list" :href="`/packing-lists/${challan.packing_list.id}`" class="doc-link">
                {{ challan.packing_list.number }}
            </Link>
            · {{ date(challan.challan_date) }} · {{ titleCase(challan.mode) }}
        </template>

        <template #actions>
            <Badge :status="challan.status" />
            <Button v-if="availableTransitions.includes('issued')" size="sm" variant="primary" @click="issueOpen = true">Issue</Button>
            <!--
                Verbs, not statuses. Beside a badge reading "Issued", a button reading
                "Delivered" is indistinguishable from a second status label.
            -->
            <Button v-if="availableTransitions.includes('in_transit')" size="sm" @click="post(transitForm)">Mark in transit</Button>
            <Button v-if="availableTransitions.includes('delivered')" size="sm" variant="primary" @click="post(deliverForm)">Mark delivered</Button>
            <Button
                v-if="['issued', 'in_transit', 'delivered'].includes(challan.status) && can('sales_invoice.create')"
                size="sm"
                @click="router.post('/invoices', { delivery_challan_id: challan.id })"
            >
                Create invoice
            </Button>
            <!-- Destructive last, after everything that moves the delivery forward. -->
            <Button v-if="availableTransitions.includes('returned')" size="sm" variant="danger" @click="returnOpen = true">Return</Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="post(useForm({ to: 'cancelled' }))">Cancel</Button>
        </template>

        <div class="space-y-4">
            <!--
                D4 — where these goods are going, and what they are going against. A delivery
                note whose customer, consignee and source documents are not on it is not a
                logistics document, and the driver, the gate and the auditor all read this one.
            -->
            <Card title="Consignee" rule="D4">
                <dl class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-ink-500">Deliver to</dt>
                        <dd class="font-medium text-ink-900">
                            <Link v-if="challan.customer" :href="`/customers/${challan.customer.id}`" class="text-brand-700 hover:underline">
                                {{ challan.customer.name }}
                            </Link>
                            <span v-else class="text-rose-600">Not set</span>
                        </dd>
                        <dd v-if="challan.customer?.code" class="text-xs text-ink-500">{{ challan.customer.code }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-ink-500">Delivery address</dt>
                        <dd v-if="challan.consignee" class="text-ink-800">
                            <span class="font-medium">{{ challan.consignee.label }}</span> — {{ challan.consignee.address }}
                        </dd>
                        <!--
                            D4. A challan that has already left says something different from
                            one that has not: the first is a record with a gap in it and the
                            second is a document that cannot be issued yet. Telling a delivered
                            challan it "cannot be issued" described a future that had already
                            happened, which is how this read as a live rule violation rather
                            than as history.
                        -->
                        <dd v-else-if="['delivered', 'returned'].includes(challan.status)" class="text-amber-700">
                            <span class="font-medium">Not recorded.</span>
                            This delivery predates the D4 check at the point of delivery, so it left
                            the factory without a destination on the paperwork. The record is kept as
                            it happened; no challan can reach this state without an address today.
                        </dd>
                        <dd v-else-if="challan.status === 'in_transit'" class="text-rose-600">
                            None set. This challan cannot be marked delivered until the order names a
                            delivery address (D4).
                        </dd>
                        <dd v-else class="text-rose-600">
                            None set. This challan cannot be issued until the order names a delivery address (D4).
                        </dd>
                        <dd v-if="challan.consignee?.route_zone" class="text-xs text-ink-500">
                            Route {{ challan.consignee.route_zone }} · {{ challan.consignee.transit_days }} day transit
                        </dd>
                    </div>
                </dl>

                <dl class="mt-4 grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-ink-500">Sales order</dt>
                        <dd>
                            <Link v-if="challan.sales_order" :href="`/sales-orders/${challan.sales_order.id}`" class="doc-link-quiet">
                                {{ challan.sales_order.number ?? `#${challan.sales_order.id}` }}
                            </Link>
                            <span v-else class="text-ink-400">—</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Customer PO</dt>
                        <dd class="text-ink-800">{{ challan.sales_order?.customer_po_no ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Packing list</dt>
                        <dd>
                            <Link v-if="challan.packing_list" :href="`/packing-lists/${challan.packing_list.id}`" class="doc-link-quiet">
                                {{ challan.packing_list.number ?? `#${challan.packing_list.id}` }}
                            </Link>
                            <span v-else class="text-ink-400">—</span>
                        </dd>
                    </div>
                </dl>
            </Card>

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
                    challan date, and issuing writes the CoC output side.
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
                label="Override reason"
                :error="issueForm.errors.override_reason"
                hint="A line is over its tolerance band. The reason is stored on the challan and read at invoicing."
                required
                class="mt-3"
            >
                <textarea v-model="issueForm.override_reason" rows="2" class="form-textarea" placeholder="Customer accepted the overrun on the phone — …" />
            </FormField>
            <template #footer>
                <Button @click="issueOpen = false">Keep as draft</Button>
                <Button
                    variant="primary"
                    :loading="issueForm.processing"
                    :disabled="issueForm.processing || (lines.some(overBand) && !issueForm.override_reason)"
                    @click="post(issueForm, () => (issueOpen = false))"
                >
                    Issue and post dispatch
                </Button>
            </template>
        </Modal>

        <Modal v-model:open="returnOpen" title="Return this delivery" subtitle="Reverses the dispatch and restores stock." width="max-w-lg">
            <FormField
                label="Failure reason"
                :error="returnForm.errors.return_reason"
                hint="Refused at the gate, wrong address, damaged in transit — it travels with the reversal."
                required
            >
                <textarea v-model="returnForm.return_reason" rows="2" class="form-textarea" placeholder="Refused at the gate — …" />
            </FormField>
            <template #footer>
                <Button @click="returnOpen = false">Back</Button>
                <Button
                    variant="danger"
                    :loading="returnForm.processing"
                    :disabled="returnForm.processing || !returnForm.return_reason"
                    @click="post(returnForm, () => (returnOpen = false))"
                >
                    Confirm return
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
