<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DocumentActions from '@/Components/Ui/DocumentActions.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Modal from '@/Components/Ui/Modal.vue';
import ActivityTrail from '@/Components/Ui/ActivityTrail.vue';
import { date, isoDate, money, pcs, rate, titleCase, todayIso } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';

const props = defineProps({
    inquiry: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    quotations: { type: Array, default: () => [] },
    /** F-06 — the order(s) this inquiry actually became, through its quotations. */
    orders: { type: Array, default: () => [] },
    /** `[{ status, at }]`, oldest first — when the inquiry moved between stages. */
    statusChanges: { type: Array, default: () => [] },
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

const confirmTransition = useTransitionConfirm('inquiry');

async function transition(status) {
    if (!(await confirmTransition(status, props.inquiry.number))) return;

    router.post(`/inquiries/${props.inquiry.id}/transition`, { status }, { preserveScroll: true });
}

/** Whole calendar days from today to a `YYYY-MM-DD`, negative when it has passed. */
function daysFromToday(value) {
    const iso = isoDate(value);

    if (!iso) return null;

    const [y, m, d] = iso.split('-').map(Number);
    const [ty, tm, td] = todayIso().split('-').map(Number);

    return Math.round((Date.UTC(y, m - 1, d) - Date.UTC(ty, tm - 1, td)) / 86400000);
}

const days = (n) => `${pcs(Math.abs(n))} ${Math.abs(n) === 1 ? 'day' : 'days'}`;

const inPlay = computed(() => ['draft', 'open', 'quoted'].includes(props.inquiry.status));

/*
 * Required-by, read as a deadline rather than a date: how long is left, or how long ago it
 * passed. Only while the inquiry is still in play — a won inquiry's date is history.
 */
const requiredBy = computed(() => {
    const diff = daysFromToday(props.inquiry.required_by);

    if (diff === null) return { text: 'Open', tone: 'text-ink-400', note: null };
    if (!inPlay.value) return { text: date(props.inquiry.required_by), tone: 'text-ink-900', note: null };
    if (diff < 0) return { text: date(props.inquiry.required_by), tone: 'text-rose-700', note: `${days(diff)} overdue` };
    if (diff === 0) return { text: date(props.inquiry.required_by), tone: 'text-amber-700', note: 'today' };

    return { text: date(props.inquiry.required_by), tone: 'text-ink-900', note: `in ${days(diff)}` };
});

const received = computed(() => {
    const diff = daysFromToday(props.inquiry.inquiry_date);

    return diff === null || diff >= 0 ? null : `${days(diff)} ago`;
});

/*
 * The life of an inquiry in four steps, with the date each was reached. Built from the
 * status changes the audit trail recorded, with the quotation list as a fallback for
 * "quoted" — an inquiry seeded before auditing covered it still has its quotations.
 */
const milestones = computed(() => {
    const status = props.inquiry.status;
    const reached = (s) => props.statusChanges.find((change) => change.status === s)?.at ?? null;
    const firstQuotation = props.quotations.length
        ? props.quotations.map((q) => q.quotation_date).filter(Boolean).sort()[0]
        : null;

    const decided = ['won', 'lost', 'cancelled'].includes(status) ? status : null;
    const order = ['draft', 'open', 'quoted', 'decided'];
    const position = decided ? 3 : order.indexOf(status);

    return [
        { key: 'received', label: 'Received', at: props.inquiry.inquiry_date, state: 'done' },
        { key: 'submitted', label: 'Submitted', at: reached('open'), state: position >= 1 ? 'done' : position === 0 ? 'next' : 'pending' },
        { key: 'quoted', label: 'Quoted', at: reached('quoted') ?? firstQuotation, state: position >= 2 ? 'done' : position === 1 ? 'next' : 'pending' },
        {
            key: 'decided',
            label: decided ? titleCase(decided) : 'Decided',
            at: decided ? reached(decided) : null,
            state: decided ? (decided === 'won' ? 'won' : 'lost') : position === 2 ? 'next' : 'pending',
        },
    ];
});

const MILESTONE_TONES = {
    done: 'border-brand-500 bg-brand-500',
    won: 'border-emerald-500 bg-emerald-500',
    lost: 'border-rose-500 bg-rose-500',
    next: 'border-brand-500 bg-white',
    pending: 'border-slate-300 bg-white',
};

const lineColumns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'description', label: 'Description' },
    { key: 'product', label: 'Product' },
    { key: 'product_type', label: 'Type' },
    { key: 'qty', label: 'Requested qty', align: 'right' },
    { key: 'target_rate_per_m', label: 'Target rate per 1,000 pcs', align: 'right' },
    { key: 'value', label: 'Indicative value', align: 'right' },
];

/** BR-1: the rate is per 1000 pieces, so a line is worth qty ÷ 1000 × rate. */
const lineValue = (line) => ((Number(line.qty) || 0) / 1000) * (Number(line.target_rate_per_m) || 0);

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
    <AppLayout :crumb="inquiry.number ?? 'Draft inquiry'">
        <Head :title="inquiry.number ?? 'Inquiry'" />

        <template #title>{{ inquiry.number ?? '(unnumbered)' }}</template>
        <template #subtitle>
            <Link v-if="inquiry.customer" :href="`/customers/${inquiry.customer.id}`" class="doc-link">
                {{ inquiry.customer.name }}
            </Link>
            <!-- A brand named after its customer says nothing twice. -->
            <template v-if="inquiry.brand && inquiry.brand.name !== inquiry.customer?.name"> · {{ inquiry.brand.name }}</template>
            · received {{ date(inquiry.inquiry_date) }}
        </template>

        <!--
            F-10 — status, then the one thing to do next, then the rest, then the destructive
            one. Every label is a verb; the badge is a state, not a button.
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

            <!--
                An inquiry raised in error had no way out: only "Mark lost", which is a different
                thing and asks for a reason the customer gave. A draft cancelled here stays
                unnumbered, so it leaves no gap in the inquiry series.
            -->
            <Button
                v-if="['draft', 'open'].includes(inquiry.status) && can('inquiry.close')"
                size="sm"
                variant="danger"
                @click="transition('cancelled')"
            >
                Cancel inquiry
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
            <ActivityTrail :entries="trail" />
            <DocumentActions document="inquiries" :id="inquiry.id" :status="inquiry.status" />
        </template>

        <div class="space-y-4">
            <div
                v-if="inquiry.lost_reason"
                class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-900"
            >
                <span class="font-medium">Lost:</span> {{ inquiry.lost_reason }}
            </div>

            <!--
                The dossier: who asked, who is handling it, when it is wanted and what it is
                worth, before the lines. These used to live only in the subtitle, so the page
                opened on a table and the deadline was a date nobody compared with today.
            -->
            <Card>
                <div class="grid gap-4 lg:grid-cols-3">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm lg:col-span-2 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs text-ink-500">Customer</dt>
                            <dd class="mt-0.5 font-medium text-ink-900">
                                <Link v-if="inquiry.customer" :href="`/customers/${inquiry.customer.id}`" class="doc-link-quiet">{{ inquiry.customer.name }}</Link>
                                <span v-else>—</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Contact</dt>
                            <dd class="mt-0.5" :class="inquiry.contact ? 'text-ink-900' : 'text-ink-400'">
                                {{ inquiry.contact?.name ?? 'Not recorded' }}
                                <span v-if="inquiry.contact?.designation" class="text-xs text-ink-500"> · {{ inquiry.contact.designation }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Brand</dt>
                            <dd class="mt-0.5" :class="inquiry.brand ? 'text-ink-900' : 'text-ink-400'">{{ inquiry.brand?.name ?? 'Not recorded' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Handled by</dt>
                            <dd class="mt-0.5" :class="inquiry.merchandiser ? 'text-ink-900' : 'text-ink-400'">{{ inquiry.merchandiser?.name ?? 'Unassigned' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Source</dt>
                            <dd class="mt-0.5" :class="inquiry.source ? 'text-ink-900' : 'text-ink-400'">{{ inquiry.source ? titleCase(inquiry.source) : 'Not recorded' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Received</dt>
                            <dd class="mt-0.5 text-ink-900">
                                {{ date(inquiry.inquiry_date) }}
                                <span v-if="received" class="text-xs text-ink-500"> · {{ received }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Required by</dt>
                            <dd class="mt-0.5 font-medium" :class="requiredBy.tone">
                                {{ requiredBy.text }}
                                <span v-if="requiredBy.note" class="text-xs font-normal"> · {{ requiredBy.note }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Asked for</dt>
                            <dd class="mt-0.5 text-ink-900 tnum">{{ pcs(inquiry.total_qty) }} pcs on {{ pcs(lines.length) }} {{ lines.length === 1 ? 'line' : 'lines' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Indicative value</dt>
                            <dd class="mt-0.5 font-semibold tnum" :class="inquiry.indicative_value > 0 ? 'text-ink-900' : 'text-ink-400'">
                                {{ inquiry.indicative_value > 0 ? money(inquiry.indicative_value, inquiry.currency?.code) : 'No target rate given' }}
                            </dd>
                        </div>
                    </dl>

                    <!-- Where it is in its life, as four steps with the date each was reached. -->
                    <ol class="flex items-start justify-between gap-1 border-t border-slate-100 pt-4 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-4" aria-label="Progress">
                        <li v-for="(step, index) in milestones" :key="step.key" class="relative flex min-w-0 flex-1 flex-col items-center text-center">
                            <span v-if="index > 0" class="absolute top-2 right-1/2 left-[-50%] h-px" :class="['done', 'won', 'lost'].includes(step.state) ? 'bg-brand-300' : 'bg-slate-200'" aria-hidden="true" />
                            <span
                                class="relative z-10 flex size-4 items-center justify-center rounded-full border-2"
                                :class="MILESTONE_TONES[step.state]"
                                aria-hidden="true"
                            >
                                <Icon v-if="['done', 'won'].includes(step.state)" name="check" size="size-2.5" class="text-white" />
                                <Icon v-else-if="step.state === 'lost'" name="close" size="size-2.5" class="text-white" />
                            </span>
                            <span class="mt-1.5 text-xs font-medium" :class="step.state === 'pending' ? 'text-ink-400' : 'text-ink-800'">{{ step.label }}</span>
                            <span class="mt-0.5 text-xs leading-tight text-ink-500">{{ step.at ? date(step.at) : (step.state === 'next' ? 'next' : '—') }}</span>
                        </li>
                    </ol>
                </div>
            </Card>

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
                        {{ value ? rate(value, inquiry.currency?.code) : '—' }}
                    </template>
                    <template #cell:value="{ row }">
                        <span v-if="lineValue(row)" class="tnum">{{ money(lineValue(row), inquiry.currency?.code) }}</span>
                        <span v-else class="text-ink-400">—</span>
                    </template>
                </DataTable>
            </Card>

            <Card title="Quotations raised" :padded="quotations.length === 0">
                <template #actions>
                    <Button v-if="canQuote" size="sm" :href="quoteHref">Quote it</Button>
                </template>

                <!-- Nothing yet is one sentence, not a 240 px box: the next step is in the header. -->
                <p v-if="quotations.length === 0" class="text-sm text-ink-600">
                    <template v-if="canQuote">
                        Nothing quoted yet. Quoting opens a draft with this customer and every line already on it; the rates come from the cost sheet.
                    </template>
                    <template v-else-if="inquiry.status === 'draft'">
                        Nothing quoted yet. Submit the inquiry first — a draft has no number to quote against.
                    </template>
                    <template v-else>
                        Nothing was quoted. This inquiry is closed, so nothing further can be quoted against it.
                    </template>
                </p>

                <DataTable
                    v-else
                    :columns="quotationColumns"
                    :rows="quotations"
                    row-key="id"
                    :row-href="(row) => `/quotations/${row.id}`"
                    dense
                >
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
