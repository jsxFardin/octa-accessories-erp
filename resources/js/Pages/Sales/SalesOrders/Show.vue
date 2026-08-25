<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { date, isoDate, money, pcs, ratePerM, relative, titleCase } from '@/plugins/formatting';
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
    jobCards: { type: Array, default: () => [] },
    fulfilment: { type: Object, default: null },
    challans: { type: Array, default: () => [] },
});

const releaseOpen = ref(false);
const releaseForm = useForm({ to: 'confirmed', release_reason: '' });

const notReady = computed(() => props.readiness.filter((r) => !r.spec || !r.artwork));

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
];
</script>

<template>
    <AppLayout>
        <Head :title="order.number ?? 'Sales order'" />

        <template #title>{{ order.number ?? '(unnumbered)' }}<span v-if="order.revision_no" class="text-ink-400">/R{{ order.revision_no }}</span></template>
        <template #subtitle>
            <Link :href="`/customers/${order.customer?.id}`" class="hover:underline">{{ order.customer?.name }}</Link>
            <span v-if="order.customer_po_no"> · PO {{ order.customer_po_no }}</span>
            · due {{ date(order.delivery_date) }}
        </template>

        <template #actions>
            <Button v-if="can('sales_order.update') && !['closed', 'cancelled'].includes(order.status)" size="sm" :href="`/sales-orders/${order.id}/edit`">Edit</Button>
            <Badge :status="order.status" />
            <Button v-if="availableTransitions.includes('confirmed')" size="sm" variant="primary"
                    @click="order.status === 'credit_hold' ? (releaseOpen = true) : transition('confirmed')">
                {{ order.status === 'credit_hold' ? 'Release credit hold' : 'Confirm' }}
            </Button>
            <Button v-if="availableTransitions.includes('closed')" size="sm" @click="transition('closed')">Close</Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="transition('cancelled')">Cancel</Button>
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
                <span class="font-medium">Credit exposure {{ money(creditCheck.exposure, order.currency) }}</span>
                against a limit of {{ money(creditCheck.credit_limit, order.currency) }}. Over by
                <strong>{{ money(creditCheck.excess, order.currency) }}</strong>. Only Accounts or the MD may release it.
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
                        <Link :href="`/delivery-challans/${challan.id}`" class="font-medium text-brand-700">{{ challan.number ?? '(draft challan)' }}</Link>
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
                            <Link v-if="row.product" :href="`/products/${row.product.id}`" class="shrink-0 font-medium text-brand-700 hover:underline">
                                {{ row.product.code }}
                            </Link>
                            <span class="truncate text-ink-500">{{ row.description ?? row.product?.name }}</span>
                        </span>
                    </template>
                    <template #cell:ordered_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:produced_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:delivered_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:remaining_qty="{ row }">{{ pcs(Math.max(0, row.ordered_qty - row.delivered_qty)) }}</template>
                    <template #cell:band="{ row }">
                        <span class="text-xs text-ink-500">{{ pcs(row.delivery_band.min) }}–{{ pcs(row.delivery_band.max) }}</span>
                    </template>
                    <template #cell:rate_per_m="{ value }">{{ ratePerM(value) }}</template>
                    <template #cell:line_total="{ value }">{{ money(value, order.currency) }}</template>
                    <template #cell:gate="{ row }">
                        <span class="flex gap-1">
                            <Badge :tone="row.spec_is_current ? 'success' : 'danger'" :label="`v${row.spec_version ?? '?'}`" />
                            <Badge :tone="row.artwork_approved ? 'success' : 'danger'" :label="row.artwork_approved ? 'art' : 'no art'" />
                        </span>
                    </template>
                    <template #cell:promised_date="{ value }">{{ date(value) }}</template>
                </DataTable>

                <template #footer>
                    <tr>
                        <td colspan="7" class="px-3 py-2 text-right text-ink-700">Order total</td>
                        <td class="px-3 py-2 text-right tnum font-semibold">{{ money(order.total, order.currency) }}</td>
                        <td colspan="2" />
                    </tr>
                </template>
            </Card>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card title="Job cards" :padded="false">
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
    </AppLayout>
</template>
