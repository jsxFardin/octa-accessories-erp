<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, isoDate, money, pcs, pct, titleCase, todayIso } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import { can, canAny } from '@/plugins/permissions';

const props = defineProps({
    queue: { type: Array, default: () => [] },
    /** What a new installation still has to do, or null once it is all done. */
    setup: { type: Object, default: null },
    tiles: { type: Object, required: true },
    orderBook: { type: Array, default: () => [] },
    /** Late, due this week and open — over every open line, not only the rows shown. */
    orderBookSummary: { type: Object, default: null },
    jobCardsByStatus: { type: Array, default: () => [] },
    artworkQueue: { type: Array, default: () => [] },
    expiringCertificates: { type: Array, default: () => [] },
    machineLoad: { type: Array, default: () => [] },
});

/*
 * Two tiers, not eight equal boxes. The three figures that mean somebody has to act today
 * get a large tile with a tone and a sentence; the rest describe the size of the business
 * and sit in one compact strip. When an attention figure is zero the tile goes quiet — grey,
 * a check mark, and the sentence says what is fine — so the eye lands on the live problem,
 * not on a row of bold zeros.
 *
 * Each href lands on the list *already narrowed to the number on the tile* — "Late orders: 14"
 * opening 400 unfiltered rows made the tile a decoration. Tiles whose count spans several
 * statuses (open orders, on the floor) link to the bare list because the filter bar is
 * single-status; the WorkQueue above stays the precise surface.
 *
 * A tile is shown only to someone who may open the list behind it. Everyone used to get all
 * eight, so a store keeper's first screen led with quotations out and artwork awaiting approval
 * — numbers they could do nothing about, on links that ended in "not allowed".
 */
const days = (n) => `${pcs(n)} ${Number(n) === 1 ? 'day' : 'days'}`;

const attention = computed(() => [
    {
        permission: 'sales_order.view_any',
        label: 'Late orders',
        count: props.tiles.late_orders,
        href: '/sales-orders?late=1',
        tone: 'danger',
        detail: props.tiles.late_orders > 0
            ? `Oldest is ${days(props.tiles.late_oldest_days)} past its delivery date.`
            : 'Every open order is inside its delivery date.',
    },
    {
        permission: 'job_card.view_any',
        label: 'Waiting on material',
        count: props.tiles.material_pending,
        href: '/job-cards?status=material_pending',
        tone: 'warning',
        detail: props.tiles.material_pending > 0
            ? 'Job cards that cannot start until stock is issued.'
            : 'Every released job card has its stock.',
    },
    {
        permission: 'artwork.view_any',
        label: 'Artwork awaiting approval',
        count: props.tiles.artwork_pending,
        href: '/artworks?state=awaiting_approval',
        tone: 'warning',
        detail: props.tiles.artwork_pending > 0
            ? 'Nothing downstream may run until the customer signs.'
            : 'No customer signature is outstanding.',
    },
].filter((tile) => can(tile.permission)));

const ATTENTION_TONES = {
    danger: {
        card: 'border-rose-200 bg-rose-50/60 hover:border-rose-300',
        label: 'text-rose-900',
        value: 'text-rose-700',
        detail: 'text-rose-800/80',
    },
    warning: {
        card: 'border-amber-200 bg-amber-50/60 hover:border-amber-300',
        label: 'text-amber-900',
        value: 'text-amber-700',
        detail: 'text-amber-900/70',
    },
    quiet: {
        card: 'border-slate-200 bg-white hover:border-brand-300',
        label: 'text-ink-600',
        value: 'text-ink-500',
        detail: 'text-ink-500',
    },
};

function attentionTone(tile) {
    return ATTENTION_TONES[tile.count > 0 ? tile.tone : 'quiet'];
}

const position = computed(() => [
    { permission: 'sales_order.view_any', label: 'Open orders', value: pcs(props.tiles.open_orders), href: '/sales-orders' },
    { permission: 'job_card.view_any', label: 'Job cards on the floor', value: pcs(props.tiles.on_floor), href: '/job-cards' },
    { permission: 'quotation.view_any', label: 'Quotations out', value: pcs(props.tiles.quotations_open), href: '/quotations?status=sent' },
    // F-12 — "Open job cards" read as "cards in production", so the two `completed` cards
    // inside it looked like a counting error. They are not: a completed card still has to be
    // closed, and closing it is somebody's job. The tile is named after what it counts, and
    // the status card below spells out the population.
    { permission: 'job_card.view_any', label: 'Job cards not yet closed', value: pcs(props.tiles.open_job_cards), href: '/job-cards?open=1' },
    // BR-47 — every other amount on this dashboard is labelled with a currency code; this one
    // passed the taka symbol, so the same figure read `৳ 1,234` here and `BDT 1,234` elsewhere.
    { permission: 'stock_lot.view_any', label: 'Stock value', value: money(props.tiles.stock_value), href: '/stock' },
].filter((tile) => can(tile.permission)));

const orderBookColumns = [
    { key: 'so_number', label: 'Order' },
    { key: 'customer_name', label: 'Customer' },
    { key: 'product_code', label: 'Product' },
    { key: 'ordered_qty', label: 'Ordered', align: 'right' },
    { key: 'delivered_qty', label: 'Delivered', align: 'right' },
    { key: 'delivered_pct', label: 'Progress', align: 'right', width: '8rem' },
    { key: 'promised_date', label: 'Promised' },
    { key: 'line_status', label: 'Status' },
];

const orderBookSubtitle = computed(() => {
    const summary = props.orderBookSummary;

    if (!summary) return 'Open lines with delivery progress';

    // "Lines", said out loud: the late-orders tile counts orders by their delivery date, this
    // counts order lines by their promised date, and the two can legitimately differ.
    return [
        summary.late > 0 ? `${pcs(summary.late)} ${summary.late === 1 ? 'line' : 'lines'} late` : 'no line late',
        `${pcs(summary.due_this_week)} due this week`,
        `${pcs(summary.open)} open`,
    ].join(' · ');
});

/** Whole calendar days from today to a `YYYY-MM-DD`, negative when it has passed. */
function daysFromToday(iso) {
    const [y, m, d] = iso.split('-').map(Number);
    const [ty, tm, td] = todayIso().split('-').map(Number);

    return Math.round((Date.UTC(y, m - 1, d) - Date.UTC(ty, tm - 1, td)) / 86400000);
}

/*
 * The promised date says how late or how soon, not only when. Twelve rows that each read
 * "Open · 0 delivered · 23 Sept" hid that most of them were already past their date; the
 * only place lateness showed was a tile two sections up. A line that has been delivered in
 * full keeps a plain date — being past it is history, not a problem.
 */
function promise(row) {
    const iso = isoDate(row.promised_date);

    if (!iso) return { text: '—', tone: 'text-ink-500' };

    const owed = Number(row.delivered_qty ?? 0) < Number(row.ordered_qty ?? 0);
    const diff = daysFromToday(iso);

    if (!owed || diff > 6) return { text: date(iso), tone: '' };
    if (diff < 0) return { text: `${date(iso)} · ${days(-diff)} late`, tone: 'font-medium text-rose-700' };
    if (diff === 0) return { text: `${date(iso)} · due today`, tone: 'font-medium text-amber-700' };

    return { text: `${date(iso)} · due in ${days(diff)}`, tone: 'text-amber-700' };
}

/*
 * One stacked bar, because the proportion is the information: "10 of 17 in production" is
 * read from a bar at a glance and from a list of four pills not at all. The colours follow
 * the status badges used everywhere else, so indigo is still "in production" here.
 */
const STATUS_BARS = {
    in_production: 'bg-indigo-500',
    released: 'bg-emerald-500',
    completed: 'bg-emerald-300',
    planned: 'bg-sky-400',
    draft: 'bg-slate-300',
    qc_pending: 'bg-amber-400',
    material_pending: 'bg-amber-500',
    on_hold: 'bg-amber-300',
};

const jobCardTotal = computed(() => props.jobCardsByStatus.reduce((sum, row) => sum + Number(row.count), 0));

const jobCardSegments = computed(() => props.jobCardsByStatus.map((row) => ({
    ...row,
    label: titleCase(row.status),
    share: jobCardTotal.value > 0 ? (Number(row.count) / jobCardTotal.value) * 100 : 0,
    colour: STATUS_BARS[row.status] ?? 'bg-slate-400',
})));

const jobCardSummary = computed(() => jobCardSegments.value.map((s) => `${s.label} ${pcs(s.count)}`).join(', '));

const loadByMachine = computed(() => {
    const grouped = {};

    for (const row of props.machineLoad) {
        grouped[row.machine_code] ??= { machine: row.machine_code, minutes: 0, operations: 0 };
        grouped[row.machine_code].minutes += Number(row.load_minutes ?? 0);
        grouped[row.machine_code].operations += Number(row.operation_count ?? 0);
    }

    return Object.values(grouped).sort((a, b) => b.minutes - a.minutes).slice(0, 8);
});

/*
 * Good news is small. A card whose only content is "Nothing scheduled." used to keep the
 * full height of its neighbours, so a factory with nothing wrong scrolled past three empty
 * boxes. Each empty card folds into one line here; the card itself appears only when it has
 * rows to show.
 */
const showArtworkQueue = computed(() => can('artwork.view_any') && props.artworkQueue.length > 0);
const showMachineLoad = computed(() => canAny('production_plan.view_any', 'job_card.view_any') && loadByMachine.value.length > 0);
const showCertificates = computed(() => canAny('certification.view_any', 'coc.view_any') && props.expiringCertificates.length > 0);

const allClear = computed(() => [
    can('artwork.view_any') && props.artworkQueue.length === 0
        && { key: 'artwork', text: 'No artwork is waiting on a customer signature.', href: '/artworks' },
    canAny('production_plan.view_any', 'job_card.view_any') && loadByMachine.value.length === 0
        && { key: 'load', text: 'Nothing is scheduled on any machine in the next 7 days.', href: can('production_plan.view_any') ? '/planning' : null },
    canAny('certification.view_any', 'coc.view_any') && props.expiringCertificates.length === 0
        && { key: 'certificates', text: 'Every certificate is current.', href: null },
].filter(Boolean));

/**
 * The list can be put away. Some reference lists are legitimately left empty, and a factory
 * that has been running for a year should not have "getting ready" above its work queue. The
 * choice is per browser — a convenience, not a record.
 */
const SETUP_HIDDEN_KEY = 'octa.setup.hidden';

function readHidden() {
    try {
        return localStorage.getItem(SETUP_HIDDEN_KEY) === '1';
    } catch {
        return false;
    }
}

const setupHidden = ref(readHidden());

function hideSetup(hidden) {
    setupHidden.value = hidden;

    try {
        localStorage.setItem(SETUP_HIDDEN_KEY, hidden ? '1' : '0');
    } catch {
        // Storage is blocked: the list is hidden for this page view only.
    }
}

/** The first step still to do is the one button; the rest are plain links. */
const nextStep = computed(() => props.setup?.steps.find((step) => !step.done) ?? null);
</script>

<template>
    <AppLayout>
        <Head title="Dashboard" />

        <template #title>Dashboard</template>
        <template #subtitle>What is waiting on you, and where orders and the floor stand</template>

        <!--
            What is waiting on this user, above what is happening in the factory. Every entry
            is gated by the permission that would let them act on it, and an empty queue is
            hidden rather than shown as a row of zeros.
        -->
        <!--
            A new installation: the order to set things up in, judged from what is in the
            database. It is a sequence, so it is numbered; done steps stay visible, ticked, so
            the list reads as progress; and it removes itself when the last step is done.
        -->
        <p v-if="setup && setupHidden" class="mb-4 text-xs text-ink-600">
            Setup is not finished: {{ setup.done }} of {{ setup.total }} steps done.
            <button type="button" class="min-h-6 font-medium text-brand-700 underline" @click="hideSetup(false)">Show the list</button>
        </p>

        <section v-if="setup && !setupHidden" class="mb-4 rounded-lg border border-brand-200 bg-white shadow-sm" aria-labelledby="setup-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-slate-200 px-4 py-3">
                <div>
                    <h2 id="setup-heading" class="text-sm font-semibold text-ink-900">Getting the system ready</h2>
                    <p class="mt-0.5 text-xs text-ink-600">
                        Work down the list. Each step unlocks the ones after it.
                    </p>
                </div>
                <p class="flex items-center gap-3 text-xs font-medium tnum text-ink-700">
                    {{ setup.done }} of {{ setup.total }} done
                    <button type="button" class="min-h-6 font-normal text-ink-600 underline hover:text-ink-900" @click="hideSetup(true)">Hide</button>
                </p>
            </div>

            <ol class="divide-y divide-slate-100">
                <li
                    v-for="(step, index) in setup.steps"
                    :key="step.key"
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3"
                    :class="step.done && 'bg-slate-50/60'"
                >
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold tnum"
                        :class="step.done
                            ? 'bg-emerald-100 text-emerald-800'
                            : step.key === nextStep?.key ? 'bg-brand-600 text-white' : 'bg-slate-100 text-ink-600'"
                        aria-hidden="true"
                    >
                        <Icon v-if="step.done" name="check" size="size-3.5" />
                        <template v-else>{{ index + 1 }}</template>
                    </span>

                    <div class="min-w-0 flex-1 basis-56">
                        <p class="text-sm font-medium" :class="step.done ? 'text-ink-600' : 'text-ink-900'">
                            {{ step.title }}
                            <span v-if="step.done" class="ml-1 text-xs font-normal text-emerald-700">Done</span>
                        </p>
                        <p v-if="!step.done" class="mt-0.5 max-w-prose text-xs leading-relaxed text-ink-600">{{ step.detail }}</p>
                    </div>

                    <Button
                        v-if="!step.done"
                        :href="step.href"
                        size="sm"
                        :variant="step.key === nextStep?.key ? 'primary' : 'secondary'"
                    >
                        {{ step.action }}
                    </Button>
                </li>
            </ol>
        </section>

        <section v-if="queue.length" class="mb-4">
            <h2 class="mb-2 text-xs font-semibold tracking-wider text-ink-400 uppercase">
                Needs you
            </h2>

            <!-- auto-fit: one entry takes the row; the most urgent thing on the page is not a third of it. -->
            <div class="grid grid-cols-[repeat(auto-fit,minmax(18rem,1fr))] gap-2">
                <Link
                    v-for="entry in queue"
                    :key="entry.key"
                    :href="entry.href"
                    class="group flex items-start gap-3 rounded-lg border bg-white p-3 transition hover:shadow-sm focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                    :class="entry.tone === 'danger'
                        ? 'border-rose-200 hover:border-rose-300'
                        : 'border-amber-200 hover:border-amber-300'"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold tnum"
                        :class="entry.tone === 'danger' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-800'"
                    >
                        {{ entry.count }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium text-ink-900">{{ entry.label }}</span>
                        <span class="mt-0.5 block text-xs leading-relaxed text-ink-500">{{ entry.hint }}</span>
                    </span>

                    <Icon name="right" size="size-4" class="mt-1 shrink-0 text-ink-300 transition group-hover:text-ink-500" />
                </Link>
            </div>
        </section>

        <div class="space-y-4">
            <!-- Tier 1: the figures that mean somebody has to act. -->
            <section v-if="attention.length" aria-label="Needs attention">
                <!-- auto-fit, so a role that sees one tile gets it at full width, not a third. -->
                <div class="grid grid-cols-[repeat(auto-fit,minmax(15rem,1fr))] gap-3">
                    <Link
                        v-for="tile in attention"
                        :key="tile.label"
                        :href="tile.href"
                        class="group flex min-h-28 flex-col rounded-lg border p-4 shadow-sm transition hover:-translate-y-px hover:shadow-md focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                        :class="attentionTone(tile).card"
                    >
                        <p class="flex items-start justify-between gap-2 text-sm font-medium" :class="attentionTone(tile).label">
                            <span>{{ tile.label }}</span>
                            <!-- The chevron is the promise: this number opens the filtered list behind it. -->
                            <Icon name="right" size="size-4" class="mt-0.5 shrink-0 text-ink-400 transition group-hover:translate-x-0.5 group-hover:text-brand-500" />
                        </p>

                        <p class="mt-2 flex items-center gap-2 text-3xl font-semibold tnum" :class="attentionTone(tile).value">
                            <template v-if="tile.count > 0">{{ pcs(tile.count) }}</template>
                            <template v-else>
                                <Icon name="check" size="size-5" class="text-emerald-600" aria-hidden="true" />
                                <span class="text-lg font-medium">None</span>
                            </template>
                        </p>

                        <p class="mt-1 text-xs leading-snug" :class="attentionTone(tile).detail">{{ tile.detail }}</p>
                    </Link>
                </div>
            </section>

            <!-- Tier 2: how big the book is. One strip, still links, still filtered. -->
            <nav v-if="position.length" aria-label="Where the business stands" class="flex flex-wrap overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <Link
                    v-for="tile in position"
                    :key="tile.label"
                    :href="tile.href"
                    class="group flex min-h-11 flex-1 basis-40 items-center justify-between gap-3 border-slate-100 px-4 py-2.5 transition not-last:border-r hover:bg-brand-50/40 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none focus-visible:ring-inset"
                >
                    <span class="text-xs leading-snug text-ink-600">{{ tile.label }}</span>
                    <span class="flex items-center gap-1 text-sm font-semibold whitespace-nowrap tnum text-ink-900">
                        {{ tile.value }}
                        <Icon name="right" size="size-3" class="text-ink-400 transition group-hover:translate-x-0.5 group-hover:text-brand-500" />
                    </span>
                </Link>
            </nav>

            <!-- Side by side only from 1536 px: at 1280 the order book lost its last two columns. -->
            <div class="grid gap-4 2xl:grid-cols-3">
                <!-- Order book -->
                <Card
                    v-if="can('sales_order.view_any')"
                    class="2xl:col-span-2"
                    title="Order book"
                    :subtitle="orderBookSubtitle"
                    :padded="false"
                >
                    <DataTable :columns="orderBookColumns" :rows="orderBook" row-key="sales_order_line_id" dense
                               empty="No open order lines.">
                        <template #cell:so_number="{ row }">
                            <Link :href="`/sales-orders/${row.sales_order_id}`" class="doc-link-quiet">
                                {{ row.so_number }}
                            </Link>
                        </template>
                        <template #cell:ordered_qty="{ value }">{{ pcs(value) }}</template>
                        <template #cell:delivered_qty="{ value }">{{ pcs(value) }}</template>
                        <template #cell:delivered_pct="{ value }">
                            <span class="inline-flex items-center justify-end gap-2">
                                <span class="h-1.5 w-14 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                                    <span
                                        class="block h-full rounded-full"
                                        :class="Number(value) >= 100 ? 'bg-emerald-500' : 'bg-brand-500'"
                                        :style="{ width: `${Math.min(100, Number(value) || 0)}%` }"
                                    />
                                </span>
                                <span class="w-8 text-right tnum">{{ pct(value, 0) }}</span>
                            </span>
                        </template>
                        <template #cell:promised_date="{ row }">
                            <span :class="promise(row).tone">{{ promise(row).text }}</span>
                        </template>
                        <template #cell:line_status="{ value }"><Badge :status="value" /></template>
                    </DataTable>
                </Card>

                <div class="space-y-4">
                    <!--
                        F-12 — the same population as the "not yet closed" figure above, and
                        titled so. It used to count every card ever raised while sitting under
                        a tile that counted only open ones; they agreed only for as long as
                        nothing had been closed.
                    -->
                    <Card
                        v-if="can('job_card.view_any')"
                        title="Open job cards by status"
                        :subtitle="jobCardTotal > 0 ? `${pcs(jobCardTotal)} not yet closed — everything except closed and cancelled` : 'Closed and cancelled cards are not counted here.'"
                    >
                        <p v-if="jobCardSegments.length === 0" class="text-sm text-ink-500">No open job cards.</p>
                        <template v-else>
                            <div
                                class="flex h-3 overflow-hidden rounded-full bg-slate-100"
                                role="img"
                                :aria-label="`Open job cards by status: ${jobCardSummary}`"
                            >
                                <span
                                    v-for="segment in jobCardSegments"
                                    :key="segment.status"
                                    class="h-full min-w-1 not-first:border-l not-first:border-white"
                                    :class="segment.colour"
                                    :style="{ flexBasis: `${segment.share}%` }"
                                />
                            </div>
                            <ul class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm" aria-hidden="true">
                                <li v-for="segment in jobCardSegments" :key="segment.status" class="flex items-center justify-between gap-2">
                                    <span class="flex min-w-0 items-center gap-2 text-ink-700">
                                        <span class="size-2.5 shrink-0 rounded-sm" :class="segment.colour" />
                                        <span class="truncate">{{ segment.label }}</span>
                                    </span>
                                    <span class="font-medium tnum text-ink-900">{{ pcs(segment.count) }}</span>
                                </li>
                            </ul>
                        </template>
                    </Card>

                    <!-- Gate 1 queue -->
                    <Card
                        v-if="showArtworkQueue"
                        title="Awaiting customer approval"
                        subtitle="Nothing downstream may run against these"
                        rule="Gate 1 · A2"
                    >
                        <ul class="divide-y divide-slate-100 text-sm">
                            <li v-for="version in artworkQueue" :key="version.id" class="flex items-center gap-3 py-2">
                                <div class="min-w-0 flex-1">
                                    <Link :href="`/artworks/${version.artwork_id}`" class="block truncate font-medium text-ink-800">
                                        {{ version.code }} · v{{ version.version_no }}
                                    </Link>
                                    <p class="truncate text-xs text-ink-500">{{ version.customer }} — {{ version.title }}</p>
                                </div>
                                <Badge
                                    :tone="version.waiting_days > 7 ? 'danger' : 'warning'"
                                    :label="`${Math.round(version.waiting_days)}d`"
                                />
                            </li>
                        </ul>
                    </Card>
                </div>
            </div>

            <div v-if="showMachineLoad || showCertificates" class="grid gap-4 lg:grid-cols-2">
                <!-- Machine load -->
                <Card v-if="showMachineLoad" title="Scheduled machine load" subtitle="Next 7 days" rule="BR-27">
                    <ul class="space-y-2">
                        <li v-for="row in loadByMachine" :key="row.machine" class="text-sm">
                            <div class="mb-1 flex items-center justify-between">
                                <span class="font-medium text-ink-700">{{ row.machine }}</span>
                                <span class="tnum text-xs text-ink-500">
                                    {{ Math.round(row.minutes / 60) }} h · {{ row.operations }} ops
                                </span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <div
                                    class="h-full rounded-full bg-brand-500"
                                    :style="{ width: `${Math.min(100, (row.minutes / (7 * 480)) * 100)}%` }"
                                />
                            </div>
                        </li>
                    </ul>
                </Card>

                <!-- Gate 2 -->
                <Card
                    v-if="showCertificates"
                    title="Certificates expiring"
                    subtitle="A shipment cannot claim a scheme whose certificate has lapsed"
                    rule="Gate 2 · BR-43"
                >
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="cert in expiringCertificates" :key="cert.id" class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <p class="font-medium text-ink-800">{{ cert.scheme.replace('_', ' ') }}</p>
                                <p class="truncate text-xs text-ink-500">{{ cert.certificate_no }}</p>
                            </div>
                            <Badge tone="danger" :label="date(cert.expires_on)" />
                        </li>
                    </ul>
                </Card>
            </div>

            <!-- What is fine, in one line each, instead of three cards saying nothing. -->
            <section v-if="allClear.length" class="rounded-lg border border-emerald-200 bg-emerald-50/50 px-4 py-3" aria-labelledby="all-clear-heading">
                <h2 id="all-clear-heading" class="text-sm font-semibold text-emerald-900">All clear</h2>
                <ul class="mt-1.5 space-y-1 text-sm text-emerald-900/80">
                    <li v-for="item in allClear" :key="item.key" class="flex items-start gap-2">
                        <Icon name="check" size="size-4" class="mt-0.5 shrink-0 text-emerald-600" aria-hidden="true" />
                        <span>
                            {{ item.text }}
                            <Link v-if="item.href" :href="item.href" class="ml-1 font-medium text-emerald-800 underline decoration-emerald-300 underline-offset-2 hover:decoration-emerald-600">Open</Link>
                        </span>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
