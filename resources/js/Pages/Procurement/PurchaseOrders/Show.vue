<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DocumentActions from '@/Components/Ui/DocumentActions.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { date, money, qty } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';

const props = defineProps({
    purchaseOrder: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    receipts: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
    approval: { type: Object, default: null },
});

/**
 * An order open to receiving is one the storekeeper can book goods against. The statuses are
 * the ones `GrnController::create()` offers, so the button never leads to a picker that does
 * not list this order.
 */
const canReceive = computed(
    () => ['approved', 'sent', 'partially_received'].includes(props.purchaseOrder.status)
        && can('grn.create'),
);

const grnHref = computed(() => `/grns/create?po=${props.purchaseOrder.id}`);

const confirmTransition = useTransitionConfirm();

async function transition(to) {
    if (!(await confirmTransition(to, props.purchaseOrder.number))) return;

    router.post(`/purchase-orders/${props.purchaseOrder.id}/transition`, { to }, { preserveScroll: true });
}

/**
 * PR-2 — above the three-quote threshold with fewer than three quotations, approval needs a
 * documented reason. The guard has always accepted one; this screen never sent it, so the
 * refusal it raises told the buyer to "approve with an override reason" through a button that
 * carried no such field. A sole-source order had no route through the interface at all.
 */
const approveOpen = ref(false);
const approveForm = useForm({ to: 'approved', override_reason: '' });

function approve() {
    if (props.approval?.needs_override) {
        approveOpen.value = true;

        return;
    }

    transition('approved');
}

/**
 * Closing a purchase order early is deliberate and was irreversible, so goods arriving against
 * one that had been closed could not be received at all. This is the way back.
 */
const reopenOpen = ref(false);
const reopenForm = useForm({ to: 'sent', reopen_reason: '' });

function reopen() {
    reopenForm.post(`/purchase-orders/${props.purchaseOrder.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => {
            reopenOpen.value = false;
            reopenForm.reset('reopen_reason');
        },
    });
}
</script>

<template>
    <AppLayout :crumb="purchaseOrder.number ?? 'Draft order'">
        <Head :title="purchaseOrder.number ?? 'Purchase order'" />

        <template #title>{{ purchaseOrder.number ?? '(unnumbered)' }}</template>
        <template #subtitle>
            {{ purchaseOrder.supplier?.name }} · ordered {{ date(purchaseOrder.order_date) }}
        </template>

        <template #actions>
            <Badge :status="purchaseOrder.status" />

            <Button v-if="purchaseOrder.status === 'draft' && can('purchase_order.update')" size="sm" :href="`/purchase-orders/${purchaseOrder.id}/edit`">
                Edit
            </Button>
            <Button v-if="availableTransitions.includes('pending_approval')" size="sm" variant="primary" @click="transition('pending_approval')">
                Submit for approval
            </Button>
            <Button v-if="availableTransitions.includes('approved')" size="sm" variant="success" @click="approve">
                Approve
            </Button>
            <!--
                05-workflows §7 "return for changes". Legal from `pending_approval` and
                permissioned like any edit, but it had no button — and `cancelled` is not
                reachable from that status either, so an order the guard refused to approve
                could not move in any direction. It was stranded on this screen.
            -->
            <Button v-if="availableTransitions.includes('draft')" size="sm" @click="transition('draft')">
                Return for changes
            </Button>
            <Button
                v-if="purchaseOrder.status === 'closed' && availableTransitions.includes('sent')"
                size="sm"
                @click="reopenOpen = true"
            >
                Reopen
            </Button>
            <!--
                Not on a closed order. `closed → sent` exists so a closed order can be reopened
                to receive a late delivery, which puts `sent` in `availableTransitions` — but
                reaching it through this button would post the order to the supplier a second
                time in the reader's mind, and skip the reason the reopen guard requires.
                Reopening has its own button, above.
            -->
            <Button
                v-if="availableTransitions.includes('sent') && purchaseOrder.status !== 'closed'"
                size="sm"
                variant="primary"
                @click="transition('sent')"
            >
                Send to supplier
            </Button>
            <!-- The goods arrive against this order; the receipt opens with it already chosen. -->
            <Button v-if="canReceive" size="sm" variant="primary" :href="grnHref">
                Receive goods
            </Button>
            <!-- Hidden below `approved`: the status list lives in DocumentRegistry, not here. -->
            <DocumentActions document="purchase-orders" :id="purchaseOrder.id" :status="purchaseOrder.status" />
            <Button v-if="availableTransitions.includes('closed')" size="sm" @click="transition('closed')">Close</Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="transition('cancelled')">
                Cancel
            </Button>
        </template>

        <div class="space-y-4">
            <!-- 06-rbac §5 — the band decides who signs, and the band is a setting. -->
            <Card v-if="approval" title="Approval" rule="06-rbac §5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <p class="text-xs text-ink-500">Order value</p>
                        <p class="text-lg font-semibold tnum text-ink-900">{{ money(approval.value, purchaseOrder.currency) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-500">Purchase manager band</p>
                        <p class="text-lg font-semibold tnum text-ink-700">{{ money(approval.band, purchaseOrder.currency) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-500">Signs off</p>
                        <p class="text-lg font-semibold text-brand-700">{{ approval.approver }}</p>
                    </div>
                </div>
            </Card>

            <Card
                title="Lines"
                rule="BR-25"
                subtitle="Quantities are rounded to the item's order multiple before the PO is raised"
                :padded="false"
            >
                <DataTable
                    :columns="[
                        { key: 'line_no', label: '#', align: 'center' },
                        { key: 'item_code', label: 'Item' },
                        { key: 'qty', label: 'Ordered', align: 'right' },
                        { key: 'received_qty', label: 'Received', align: 'right' },
                        { key: 'rate', label: 'Rate', align: 'right' },
                        { key: 'amount', label: 'Amount', align: 'right' },
                        { key: 'expected_date', label: 'Expected' },
                        { key: 'cert_claim', label: 'Claim required' },
                    ]"
                    :rows="lines"
                    row-key="id"
                    empty="No lines."
                    dense
                >
                    <template #cell:item_code="{ row }">
                        <span class="font-medium">{{ row.item_code }}</span>
                        <span class="text-ink-500"> {{ row.item_name }}</span>
                    </template>
                    <template #cell:qty="{ row }">{{ qty(row.qty) }} {{ row.uom }}</template>
                    <template #cell:received_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:rate="{ value }">{{ money(value, purchaseOrder.currency) }}</template>
                    <template #cell:amount="{ value }">{{ money(value, purchaseOrder.currency) }}</template>
                    <template #cell:expected_date="{ value }">{{ value ? date(value) : '—' }}</template>
                    <template #cell:cert_claim="{ value }">
                        <!-- A line that demands a claim makes the GRN's certification fields mandatory -->
                        <Badge v-if="value" tone="success" :label="value" />
                        <span v-else class="text-ink-400">—</span>
                    </template>
                </DataTable>
            </Card>

            <Card title="Goods receipts" :padded="false">
                <template #actions>
                    <Button v-if="canReceive" size="sm" :href="grnHref">Receive goods</Button>
                </template>

                <DataTable
                    :columns="[
                        { key: 'number', label: 'GRN' },
                        { key: 'received_on', label: 'Received' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="receipts"
                    row-key="id"
                    :row-href="(row) => `/grns/${row.id}`"
                    empty="Nothing received against this order yet."
                    dense
                >
                    <template #empty>
                        <EmptyState
                            icon="goods-receipt"
                            title="Nothing received against this order yet"
                            :description="canReceive
                                ? 'A goods receipt books the delivery into stock and is what a supplier bill is later matched against.'
                                : 'Goods can be booked in once the order has been approved.'"
                            :action-label="canReceive ? 'Receive goods' : null"
                            :action-href="canReceive ? grnHref : null"
                        />
                    </template>

                    <template #cell:received_on="{ value }">{{ date(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>

        <Modal
            v-model:open="approveOpen"
            title="Approve without three quotations"
            subtitle="A sole-source or urgent order is legitimate, but the reason is recorded on the order and audit-logged."
        >
            <p v-if="approval" class="mb-3 text-sm text-ink-600">
                This order is worth {{ money(approval.value) }}, above the
                {{ money(approval.quote_threshold) }} threshold that requires three supplier
                quotations. It has
                {{ approval.quotations === 0 ? 'none' : approval.quotations }}.
            </p>
            <FormField label="Override reason" :error="approveForm.errors.override_reason" required>
                <textarea
                    v-model="approveForm.override_reason"
                    rows="3"
                    class="form-textarea"
                    placeholder="Only approved supplier for this yarn — …"
                />
            </FormField>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="success"
                    :disabled="!approveForm.override_reason"
                    :loading="approveForm.processing"
                    @click="approveForm.post(`/purchase-orders/${purchaseOrder.id}/transition`, { onSuccess: () => (approveOpen = false) })"
                >
                    Approve
                </Button>
            </template>
        </Modal>

        <Modal
            v-model:open="reopenOpen"
            title="Reopen this purchase order"
            subtitle="Back to sent, so goods can be received against it again."
        >
            <p class="mb-3 text-sm text-ink-600">
                The order returns to <em>sent</em> whichever state it was closed from. Line
                quantities are untouched, so the next posted goods receipt will roll the status
                back up to partially received or received on its own. The supplier is not
                re-notified.
            </p>
            <FormField
                label="Why is this order being reopened?"
                :error="reopenForm.errors.reopen_reason"
                hint="Recorded on the order's history. Closing is normally final."
                required
            >
                <textarea
                    v-model="reopenForm.reopen_reason"
                    rows="2"
                    class="form-textarea"
                    placeholder="Balance delivered after the order was closed — …"
                />
            </FormField>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="primary"
                    :disabled="!reopenForm.reopen_reason"
                    :loading="reopenForm.processing"
                    @click="reopen"
                >
                    Reopen
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
