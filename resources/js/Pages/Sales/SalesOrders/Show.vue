<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DocumentActions from '@/Components/Ui/DocumentActions.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import ActivityTrail from '@/Components/Ui/ActivityTrail.vue';
import { baseCurrency, date, isoDate, money, pcs, ratePerM, relative, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';

/** A date-shaped amendment value renders as a date; anything else renders as itself. */
function amendValue(value) {
    if (value === null || value === undefined || value === '') return '—';

    return /^\d{4}-\d{2}-\d{2}/.test(String(value)) ? date(isoDate(value)) : value;
}

const props = defineProps({
    order: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    readiness: { type: Array, default: () => [] },
    creditCheck: { type: Object, required: true },
    availableTransitions: { type: Array, default: () => [] },
    amendments: { type: Array, default: () => [] },
    /** F-01/F-02 — this order's own history. */
    trail: { type: Array, default: () => [] },
    jobCards: { type: Array, default: () => [] },
    fulfilment: { type: Object, default: null },
    challans: { type: Array, default: () => [] },
});

const releaseOpen = ref(false);
const releaseForm = useForm({ to: 'confirmed', release_reason: '' });

/**
 * BR-45 — short-closing an order, and cancelling one that has already produced something, are
 * both decisions someone signs for: the guard wants a `close_reason` and refuses without one.
 * The buttons sent the target status alone, so both refused every time and the reason they
 * asked for could not be given. Only the paths that actually need a reason open the dialog —
 * closing a fully delivered order, or cancelling one nothing has been made against, stays a
 * single click.
 */
const closeOpen = ref(false);
const closeForm = useForm({ to: 'closed', close_reason: '' });

const cancelOpen = ref(false);
const cancelForm = useForm({ to: 'cancelled', close_reason: '' });

/** Anything made against this order is what turns a cancellation into a signed decision. */
const hasProduced = computed(() => props.lines.some((line) => Number(line.produced_qty) > 0));

function close() {
    if (props.order.status === 'partially_delivered') {
        closeOpen.value = true;

        return;
    }

    transition('closed');
}

function cancel() {
    if (hasProduced.value) {
        cancelOpen.value = true;

        return;
    }

    transition('cancelled');
}

const notReady = computed(() => props.readiness.filter((r) => !r.spec || !r.artwork));

/**
 * What can happen next, from this order.
 *
 * The conditions mirror the ones the target screens enforce — `JobCardController::create()`
 * offers exactly the lines of a confirmed order with quantity left, and the packing list is
 * drafted against an order in the same three statuses. Showing an action the next screen
 * would then refuse is worse than not showing it.
 */
const IN_FLIGHT = ['confirmed', 'in_production', 'partially_delivered'];

const inFlight = computed(() => IN_FLIGHT.includes(props.order.status));

/** Lines with quantity still to make — one job card's worth of work each, at least. */
const linesToMake = computed(() =>
    props.lines.filter((line) => Number(line.ordered_qty) > Number(line.produced_qty)),
);

const canRaiseJobCard = computed(
    () => inFlight.value && linesToMake.value.length > 0 && can('job_card.create'),
);

const canPack = computed(() => inFlight.value && can('packing_list.create'));

function jobCardHref(line = null) {
    const base = `/job-cards/create?sales_order=${props.order.id}`;

    return line ? `${base}&sales_order_line=${line.id}` : base;
}

const confirmTransition = useTransitionConfirm();

async function transition(to) {
    if (!(await confirmTransition(to, props.order.number))) return;

    router.post(`/sales-orders/${props.order.id}/transition`, { to }, { preserveScroll: true });
}

const lineColumns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'product', label: 'Product' },
    { key: 'ordered_qty', label: 'Ordered', align: 'right' },
    { key: 'produced_qty', label: 'Produced', align: 'right' },
    { key: 'delivered_qty', label: 'Delivered', align: 'right' },
    { key: 'remaining_qty', label: 'Remaining', align: 'right' },
    { key: 'band', label: 'Acceptable band', align: 'right' },
    { key: 'rate_per_m', label: 'Rate /M', align: 'right' },
    { key: 'line_total', label: 'Value', align: 'right' },
    { key: 'gate', label: 'Gate 1' },
    { key: 'promised_date', label: 'Promised' },
    { key: 'make', label: '', width: '5.5rem', align: 'right' },
];
</script>

<template>
    <AppLayout :crumb="order.number ?? 'Draft order'">
        <Head :title="order.number ?? 'Sales order'" />

        <template #title>{{ order.number ?? '(unnumbered)' }}<span v-if="order.revision_no" class="text-ink-400">/R{{ order.revision_no }}</span></template>
        <template #subtitle>
            <Link :href="`/customers/${order.customer?.id}`" class="doc-link">{{ order.customer?.name }}</Link>
            <span v-if="order.customer_po_no"> · PO {{ order.customer_po_no }}</span>
            · due {{ date(order.delivery_date) }}
        </template>

        <!--
            Status first and on its own — it is a fact, not a button. Then the one thing this
            order is most likely waiting for, then the rest, then the destructive ones.
        -->
        <template #actions>
            <Badge :status="order.status" />

            <Button v-if="availableTransitions.includes('confirmed')" size="sm" variant="primary"
                    @click="order.status === 'credit_hold' ? (releaseOpen = true) : transition('confirmed')">
                {{ order.status === 'credit_hold' ? 'Release credit hold' : 'Confirm' }}
            </Button>

            <!-- A confirmed order's next document is a job card; it opens with this order on it. -->
            <Button v-if="canRaiseJobCard" size="sm" variant="primary" :href="jobCardHref()">
                Create job card
            </Button>

            <Button v-if="canPack" size="sm" :href="`/packing-lists/create?sales_order=${order.id}`">
                Start packing list
            </Button>

            <Button v-if="can('sales_order.update') && !['closed', 'cancelled'].includes(order.status)" size="sm" :href="`/sales-orders/${order.id}/edit`">Edit</Button>
            <Button v-if="availableTransitions.includes('closed')" size="sm" @click="close">
                {{ order.status === 'partially_delivered' ? 'Short close' : 'Close' }}
            </Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="cancel">Cancel</Button>
            <!-- F-01/F-02 — confirmed, held, amended, closed: recorded all along, shown nowhere. -->
            <ActivityTrail :entries="trail" />
            <DocumentActions document="sales-orders" :id="order.id" :status="order.status" />
        </template>

        <div class="space-y-4">
            <!-- S3: what blocks confirmation, stated before the button is pressed -->
            <div
                v-if="notReady.length && order.status === 'draft'"
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900"
            >
                <p class="font-medium">This order cannot be confirmed yet.</p>
                <ul class="mt-1 list-disc pl-5 text-xs">
                    <li v-for="row in notReady" :key="row.line_no">
                        Line {{ row.line_no }} ({{ row.product }}):
                        <span v-if="!row.spec">no current spec</span>
                        <span v-if="!row.spec && !row.artwork">, </span>
                        <span v-if="!row.artwork">no approved artwork version</span>
                    </li>
                </ul>
            </div>

            <!-- BR-46 -->
            <div
                v-if="creditCheck.on_hold"
                class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-900"
            >
                <!-- BR-46/BR-51 — the credit decision is made in the factory's currency: the
                     limit is stated in it and every document is converted at its own snapshotted
                     rate before it is added in. Labelling these with the *order's* currency
                     said `USD 1,225,000` for a figure that is BDT. -->
                <span class="font-medium">Credit exposure {{ money(creditCheck.exposure, baseCurrency()) }}</span>
                against a limit of {{ money(creditCheck.credit_limit, baseCurrency()) }}. Over by
                <strong>{{ money(creditCheck.excess, baseCurrency()) }}</strong>. Only Accounts or the MD may release it.
            </div>

            <!-- P0-4: every figure from its authoritative source; gaps shown, never smoothed -->
            <Card v-if="fulfilment" title="Fulfilment" rule="P0-4">
                <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-7">
                    <div><dt class="text-xs text-ink-500">Ordered</dt><dd class="font-medium tnum">{{ pcs(fulfilment.ordered) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Produced</dt><dd class="font-medium tnum">{{ pcs(fulfilment.produced) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">FG received</dt><dd class="font-medium tnum">{{ pcs(fulfilment.fg_received) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">FG available</dt><dd class="font-medium tnum text-emerald-700">{{ pcs(fulfilment.fg_available) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Packed</dt><dd class="font-medium tnum">{{ pcs(fulfilment.packed) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Delivered</dt><dd class="font-medium tnum">{{ pcs(fulfilment.delivered) }}</dd></div>
                    <div v-if="fulfilment.credited_value > 0"><dt class="text-xs text-ink-500">Credited value</dt><dd class="font-medium tnum text-amber-700">{{ money(fulfilment.credited_value, order.currency) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Remaining</dt><dd class="font-medium tnum" :class="fulfilment.ordered - fulfilment.delivered > 0 ? 'text-rose-600' : ''">{{ pcs(Math.max(0, fulfilment.ordered - fulfilment.delivered)) }}</dd></div>
                </dl>
                <ul v-if="challans.length" class="mt-3 divide-y divide-slate-100 border-t border-slate-100 text-sm">
                    <li v-for="challan in challans" :key="challan.id" class="flex items-center justify-between py-1.5">
                        <Link :href="`/delivery-challans/${challan.id}`" class="doc-link-quiet">{{ challan.number ?? '(draft challan)' }}</Link>
                        <span class="tnum">{{ pcs(challan.total_qty) }}</span>
                        <span class="text-xs text-ink-500">{{ date(challan.challan_date) }}</span>
                        <Badge :status="challan.status" />
                    </li>
                </ul>
            </Card>

            <Card title="Lines" rule="BR-1 · BR-44" :padded="false">
                <DataTable :columns="lineColumns" :rows="lines" row-key="id" empty="No lines." dense>
                    <template #cell:product="{ row }">
                        <span class="inline-flex min-w-0 items-baseline gap-1.5">
                            <Link v-if="row.product" :href="`/products/${row.product.id}`" class="doc-link-quiet shrink-0">
                                {{ row.product.code }}
                            </Link>
                            <span class="truncate text-ink-500">{{ row.description ?? row.product?.name }}</span>
                        </span>
                    </template>
                    <!--
                        BR-53 — an order amended downwards after its job cards were raised
                        leaves the floor committed to more than the order can take. The work is
                        not undone (S1 keeps the order above what was made); the surplus is a
                        decision somebody has to make, and it cannot be made if nobody is told.
                    -->
                    <template #cell:ordered_qty="{ row, value }">
                        {{ pcs(value) }} pcs
                        <span
                            v-if="row.over_allocation"
                            class="mt-0.5 block text-[11px] font-medium text-amber-700"
                            :title="`${pcs(row.over_allocation.committed)} pcs committed to ${row.over_allocation.live_cards} live job card(s) against an allowance of ${pcs(row.over_allocation.allowance)} pcs.`"
                        >
                            {{ pcs(row.over_allocation.excess) }} pcs over-allocated
                        </span>
                    </template>
                    <template #cell:produced_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:delivered_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:remaining_qty="{ row }">{{ pcs(Math.max(0, row.ordered_qty - row.delivered_qty)) }}</template>
                    <template #cell:band="{ row }">
                        <span class="text-xs text-ink-500">{{ pcs(row.delivery_band.min) }}–{{ pcs(row.delivery_band.max) }}</span>
                    </template>
                    <template #cell:rate_per_m="{ value }">{{ ratePerM(value, order.currency) }}</template>
                    <template #cell:line_total="{ value }">{{ money(value, order.currency) }}</template>
                    <template #cell:gate="{ row }">
                        <span class="flex gap-1">
                            <Badge :tone="row.spec_is_current ? 'success' : 'danger'" :label="`v${row.spec_version ?? '?'}`" />
                            <Badge :tone="row.artwork_approved ? 'success' : 'danger'" :label="row.artwork_approved ? 'art' : 'no art'" />
                        </span>
                    </template>
                    <template #cell:promised_date="{ value }">{{ date(value) }}</template>
                    <!--
                        The line is where the planner actually decides; sending them to a list
                        of every open line in the factory to find the one already on screen was
                        the long way round.
                    -->
                    <template #cell:make="{ row }">
                        <Button
                            v-if="canRaiseJobCard && Number(row.ordered_qty) > Number(row.produced_qty)"
                            size="sm"
                            :href="jobCardHref(row)"
                        >
                            Make
                        </Button>
                    </template>

                    <!--
                        Belongs to the table, not the card: `Card` has no footer slot, so this
                        total silently rendered nowhere and its colspans had drifted off the
                        column count with it.
                    -->
                    <template #footer>
                        <tr>
                            <td colspan="8" class="px-3 py-2 text-right text-ink-700">Order total</td>
                            <td class="px-3 py-2 text-right tnum font-semibold">{{ money(order.total, order.currency) }}</td>
                            <td colspan="3" />
                        </tr>
                    </template>
                </DataTable>
            </Card>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card title="Job cards" :padded="false">
                    <template #actions>
                        <Button v-if="canRaiseJobCard" size="sm" :href="jobCardHref()">Create job card</Button>
                    </template>

                    <DataTable
                        :columns="[
                            { key: 'number', label: 'Number' },
                            { key: 'planned_qty', label: 'Planned', align: 'right' },
                            { key: 'good_qty', label: 'Good', align: 'right' },
                            { key: 'due_date', label: 'Due' },
                            { key: 'status', label: 'Status' },
                        ]"
                        :rows="jobCards"
                        row-key="id"
                        :row-href="(row) => `/job-cards/${row.id}`"
                        empty="No job cards raised yet."
                        dense
                    >
                        <template #empty>
                            <EmptyState
                                icon="job-card"
                                title="No job cards for this order yet"
                                :description="canRaiseJobCard
                                    ? 'Nothing is on the floor against it. A card carries one line of this order into production.'
                                    : inFlight
                                        ? 'Nothing is on the floor against it yet.'
                                        : 'A job card can only be raised once the order is confirmed.'"
                                :action-label="canRaiseJobCard ? 'Create job card' : null"
                                :action-href="canRaiseJobCard ? jobCardHref() : null"
                            />
                        </template>

                        <template #cell:planned_qty="{ value }">{{ pcs(value) }}</template>
                        <template #cell:good_qty="{ value }">{{ pcs(value) }}</template>
                        <template #cell:due_date="{ value }">{{ date(value) }}</template>
                        <template #cell:status="{ value }"><Badge :status="value" /></template>
                    </DataTable>
                </Card>

                <!-- S2: no silent edits after confirmation -->
                <Card title="Amendments" rule="S2" subtitle="Every post-confirmation change, with its reason and author" :padded="false">
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="amendment in amendments" :key="amendment.id" class="px-3 py-2">
                            <p class="font-medium text-ink-800">
                                R{{ amendment.revision_no }} · {{ titleCase(amendment.changed_field) }}
                            </p>
                            <!-- Both sides formatted the same way: `2026-08-31 00:00:00 → 2026-08-31`
                                 read like a database diff, not a change to an order. -->
                            <p class="text-xs text-ink-500">
                                <template v-if="amendValue(amendment.old_value) === amendValue(amendment.new_value)">
                                    No effective change — the value was re-saved in a different format.
                                </template>
                                <template v-else>
                                    {{ amendValue(amendment.old_value) }} → {{ amendValue(amendment.new_value) }}
                                </template>
                            </p>
                            <p class="mt-0.5 text-xs text-ink-700">{{ amendment.reason }}</p>
                            <p v-if="amendment.changed_by || amendment.created_at" class="mt-0.5 text-[11px] text-ink-400">
                                <template v-if="amendment.changed_by">{{ amendment.changed_by }} · </template>{{ relative(amendment.created_at) }}
                            </p>
                        </li>
                        <li v-if="amendments.length === 0" class="px-3 py-6 text-center text-ink-500">
                            No amendments.
                        </li>
                    </ul>
                </Card>
            </div>
        </div>

        <Modal v-model:open="releaseOpen" title="Release the credit hold" subtitle="Audit-logged, and only Accounts or the MD may do it.">
            <FormField label="Reason" :error="releaseForm.errors.release_reason" required>
                <textarea v-model="releaseForm.release_reason" rows="3" class="form-textarea" />
            </FormField>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="primary"
                    :disabled="!releaseForm.release_reason"
                    :loading="releaseForm.processing"
                    @click="releaseForm.post(`/sales-orders/${order.id}/transition`, { onSuccess: () => (releaseOpen = false) })"
                >
                    Release and confirm
                </Button>
            </template>
        </Modal>

        <Modal
            v-model:open="closeOpen"
            title="Short close this order"
            subtitle="Closing below the ordered quantity releases the reservations and ends the order early."
        >
            <FormField label="Reason" :error="closeForm.errors.close_reason" required>
                <textarea
                    v-model="closeForm.close_reason"
                    rows="3"
                    class="form-textarea"
                    placeholder="Customer accepted the short shipment — …"
                />
            </FormField>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Cancel</Button>
                <Button
                    variant="primary"
                    :disabled="!closeForm.close_reason"
                    :loading="closeForm.processing"
                    @click="closeForm.post(`/sales-orders/${order.id}/transition`, { onSuccess: () => (closeOpen = false) })"
                >
                    Short close
                </Button>
            </template>
        </Modal>

        <Modal
            v-model:open="cancelOpen"
            title="Cancel an order with production against it"
            subtitle="Something has already been made on this order, so the cancellation is recorded with its reason."
        >
            <FormField label="Reason" :error="cancelForm.errors.close_reason" required>
                <textarea
                    v-model="cancelForm.close_reason"
                    rows="3"
                    class="form-textarea"
                    placeholder="Customer withdrew the programme — …"
                />
            </FormField>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Keep the order</Button>
                <Button
                    variant="danger"
                    :disabled="!cancelForm.close_reason"
                    :loading="cancelForm.processing"
                    @click="cancelForm.post(`/sales-orders/${order.id}/transition`, { onSuccess: () => (cancelOpen = false) })"
                >
                    Cancel the order
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
