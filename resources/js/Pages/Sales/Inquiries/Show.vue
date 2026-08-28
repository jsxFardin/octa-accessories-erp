<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import ActivityTrail from '@/Components/Ui/ActivityTrail.vue';
import { date, money, pcs, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';

const props = defineProps({
    inquiry: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    quotations: { type: Array, default: () => [] },
    /** F-06 — the order(s) this inquiry actually became, through its quotations. */
    orders: { type: Array, default: () => [] },
    /** F-01/F-02 — this inquiry's own history. */
    trail: { type: Array, default: () => [] },
});

/** The one action an open inquiry exists for; the same rule the button in the header uses. */
const canQuote = computed(
    () => ['open', 'quoted'].includes(props.inquiry.status) && can('quotation.create'),
);

const quoteHref = computed(() => `/quotations/create?inquiry=${props.inquiry.id}`);

const lostOpen = ref(false);
const lostForm = useForm({ status: 'lost', lost_reason: '' });

const confirmTransition = useTransitionConfirm();

async function transition(status) {
    if (!(await confirmTransition(status, props.inquiry.number))) return;

    router.post(`/inquiries/${props.inquiry.id}/transition`, { status }, { preserveScroll: true });
}

const lineColumns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'description', label: 'Description' },
    { key: 'product', label: 'Product' },
    { key: 'product_type', label: 'Type' },
    { key: 'qty', label: 'Requested qty', align: 'right' },
    { key: 'target_rate_per_m', label: 'Target rate', align: 'right' },
];

const quotationColumns = [
    { key: 'number', label: 'Number' },
    { key: 'quotation_date', label: 'Date' },
    { key: 'total', label: 'Value', align: 'right' },
    { key: 'status', label: 'Status' },
];

const orderColumns = [
    { key: 'number', label: 'Number' },
    { key: 'quotation_number', label: 'From quotation' },
    { key: 'order_date', label: 'Ordered' },
    { key: 'delivery_date', label: 'Due' },
    { key: 'total', label: 'Value', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head :title="inquiry.number ?? 'Inquiry'" />

        <template #title>{{ inquiry.number ?? '(unnumbered)' }}</template>
        <template #subtitle>
            <Link v-if="inquiry.customer" :href="`/customers/${inquiry.customer.id}`" class="doc-link">
                {{ inquiry.customer.name }}
            </Link>
            · received {{ date(inquiry.inquiry_date) }}
            <span v-if="inquiry.required_by"> · required by {{ date(inquiry.required_by) }}</span>
        </template>

        <!--
            F-10 — status, then the one thing to do next, then the rest, then the destructive
            one. The order used to be Status → Edit → Quote it → Mark lost, which put the
            action the document exists for third and read as a different hierarchy from every
            other detail page. Every label is a verb; the badge is a state, not a button.
        -->
        <template #actions>
            <Badge :status="inquiry.status" />

            <!-- Primary: the single next step. A draft is submitted; an open inquiry is quoted. -->
            <!-- A draft with no lines cannot be submitted; the number is assigned here (BR-34). -->
            <Button
                v-if="inquiry.status === 'draft' && can('inquiry.submit')"
                variant="primary"
                size="sm"
                @click="transition('open')"
            >
                Submit inquiry
            </Button>

            <Button v-if="canQuote" variant="primary" size="sm" :href="quoteHref">
                Quote it
            </Button>

            <!-- Secondary. -->
            <Button
                v-if="['draft', 'open'].includes(inquiry.status) && can('inquiry.update')"
                size="sm"
                :href="`/inquiries/${inquiry.id}/edit`"
            >
                Edit
            </Button>

            <!-- Destructive, last. -->
            <Button
                v-if="['open', 'quoted'].includes(inquiry.status) && can('inquiry.close')"
                size="sm"
                variant="danger"
                @click="lostOpen = true"
            >
                Mark lost
            </Button>
        </template>

        <div class="space-y-4">
            <div
                v-if="inquiry.lost_reason"
                class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-900"
            >
                <span class="font-medium">Lost:</span> {{ inquiry.lost_reason }}
            </div>

            <Card title="Lines" :padded="false">
                <DataTable :columns="lineColumns" :rows="lines" row-key="id" empty="No lines." dense>
                    <template #cell:product="{ row }">
                        <Link v-if="row.product" :href="`/products/${row.product.id}`" class="doc-link-quiet">
                            {{ row.product.code }}
                        </Link>
                        <span v-else class="text-ink-400">not yet a product</span>
                    </template>
                    <template #cell:product_type="{ value }">{{ value ? titleCase(value) : '—' }}</template>
                    <template #cell:qty="{ value }">{{ pcs(value) }} pcs</template>
                    <!-- An inquiry carries no currency of its own; the target is understood in
                         the currency the customer trades in, so that is what is shown. -->
                    <template #cell:target_rate_per_m="{ value }">
                        {{ value ? ratePerM(value, inquiry.currency) : '—' }}
                    </template>
                </DataTable>
            </Card>

            <Card title="Quotations raised" :padded="false">
                <template #actions>
                    <Button v-if="canQuote" size="sm" :href="quoteHref">Quote it</Button>
                </template>

                <DataTable
                    :columns="quotationColumns"
                    :rows="quotations"
                    row-key="id"
                    :row-href="(row) => `/quotations/${row.id}`"
                    empty="Nothing quoted yet."
                    dense
                >
                    <template #empty>
                        <EmptyState
                            icon="quote"
                            title="No quotation has been raised for this inquiry"
                            :description="canQuote
                                ? 'Quoting it opens a draft with this customer and every line already on it — the rates are computed from the cost sheet.'
                                : inquiry.status === 'draft'
                                    ? 'Submit the inquiry first; a draft has no number to quote against.'
                                    : 'This inquiry is closed, so nothing further can be quoted against it.'"
                            :action-label="canQuote ? 'Quote it' : null"
                            :action-href="canQuote ? quoteHref : null"
                        />
                    </template>

                    <!-- F-11 — these were plain body text, so the only way to find out the
                         row led anywhere was to click it. -->
                    <template #cell:number="{ row }">
                        <Link :href="`/quotations/${row.id}`" class="doc-link-quiet">{{ row.number ?? '(unnumbered)' }}</Link><span
                            v-if="row.revision_no" class="text-ink-400">/R{{ row.revision_no }}</span>
                    </template>
                    <template #cell:quotation_date="{ value }">{{ date(value) }}</template>
                    <!-- BR-47 — the quotation's own currency, not the factory's. Most of these
                         are raised in USD and every one of them used to read as BDT. -->
                    <template #cell:total="{ row, value }">{{ money(value, row.currency) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>

            <!--
                F-06 — the far end of the chain. A Won inquiry showed the quotations it had
                raised and stopped, so the order it was actually won with could only be found
                by searching for it. One quotation may hold more than one order over its life
                (Q5 lets a cancelled order be re-raised), so this is a list, not a field.
            -->
            <Card v-if="orders.length" title="Sales orders won" :padded="false">
                <DataTable
                    :columns="orderColumns"
                    :rows="orders"
                    row-key="id"
                    :row-href="(row) => `/sales-orders/${row.id}`"
                    dense
                >
                    <template #cell:number="{ row }">
                        <Link :href="`/sales-orders/${row.id}`" class="doc-link-quiet">{{ row.number ?? `draft order #${row.id}` }}</Link>
                    </template>
                    <template #cell:quotation_number="{ row }">
                        <Link :href="`/quotations/${row.quotation_id}`" class="doc-link-quiet">{{ row.quotation_number ?? '(unnumbered)' }}</Link>
                    </template>
                    <template #cell:order_date="{ value }">{{ date(value) }}</template>
                    <template #cell:delivery_date="{ value }">{{ date(value) }}</template>
                    <template #cell:total="{ row, value }">{{ money(value, row.currency) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>

            <!--
                A Won inquiry with no order behind it is a real state — the order may still be
                being raised — but it is worth saying rather than leaving the page to end.
            -->
            <div
                v-else-if="inquiry.status === 'won'"
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900"
            >
                This inquiry is marked won, but no sales order has been raised from its
                quotations yet. Convert the accepted quotation to create one.
            </div>

            <Card v-if="inquiry.notes" title="Notes">
                <p class="text-sm whitespace-pre-line text-ink-700">{{ inquiry.notes }}</p>
            </Card>

            <ActivityTrail :entries="trail" title="Activity" />
        </div>

        <Modal v-model:open="lostOpen" title="Mark this inquiry lost" subtitle="The reason feeds win/loss analysis.">
            <FormField label="Reason" :error="lostForm.errors.lost_reason" required>
                <textarea v-model="lostForm.lost_reason" rows="3" class="form-textarea" />
            </FormField>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="danger"
                    :disabled="!lostForm.lost_reason"
                    :loading="lostForm.processing"
                    @click="lostForm.post(`/inquiries/${inquiry.id}/transition`, { onSuccess: () => (lostOpen = false) })"
                >
                    Mark lost
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
