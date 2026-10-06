<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import Chart from '@/Components/Ui/Chart.vue';
import Icon from '@/Components/Ui/Icon.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import StatTile from '@/Components/Ui/StatTile.vue';
import { date, isoDate, money, number, pcs, pct, titleCase, todayIso } from '@/plugins/formatting';
import { CHART } from '@/plugins/chartTheme';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import { can, canAny } from '@/plugins/permissions';

const props = defineProps({
    queue: { type: Array, default: () => [] },
    /** What a new installation still has to do, or null once it is all done. */
    setup: { type: Object, default: null },
    tiles: { type: Object, required: true },
    /** The last month as flow, against the month before; sections the user may not see are null. */
    analytics: { type: Object, default: () => ({}) },
    orderBook: { type: Array, default: () => [] },
    /** Late, due this week and open — over every open line, not only the rows shown. */
    orderBookSummary: { type: Object, default: null },
    jobCardsByStatus: { type: Array, default: () => [] },
    artworkQueue: { type: Array, default: () => [] },
    expiringCertificates: { type: Array, default: () => [] },
    machineLoad: { type: Array, default: () => [] },
});

const days = (n) => `${pcs(n)} ${Number(n) === 1 ? 'day' : 'days'}`;
const windowLabel = computed(() => `last ${props.analytics.window_days ?? 30} days`);
const previousLabel = computed(() => `previous ${props.analytics.window_days ?? 30} days`);

/* Short calendar labels for axis ticks: "22 Sep". The organisation's full date format is for documents, not a 40 px tick. */
const tick = (iso) => {
    const [y, m, d] = isoDate(iso).split('-').map(Number);

    return new Date(y, m - 1, d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
};

/* Compact figures for a tile: 12,34,567 is honest but 12.3 lakh reads at a glance. */
const compact = (value) => {
    const n = Number(value) || 0;
    const abs = Math.abs(n);

    if (abs >= 10_000_000) return `${number(n / 10_000_000, 1)} cr`;
    if (abs >= 100_000) return `${number(n / 100_000, 1)} lakh`;

    return pcs(n);
};

/*
 * The figures, in two groups. The first row answers "how is the month going": in, out, on
 * time, won, paid. Late orders leads it because it is the one figure that is a problem by
 * itself; the rest are rates and flows with a direction. Every tile is shown only to someone
 * who may open the list behind it, and every one opens that list already filtered.
 */
const kpis = computed(() => {
    const a = props.analytics;
    const tiles = [];

    if (can('sales_order.view_any')) {
        tiles.push({
            key: 'late',
            label: 'Late orders',
            value: pcs(props.tiles.late_orders),
            sub: props.tiles.late_orders > 0
                ? `Oldest is ${days(props.tiles.late_oldest_days)} past its delivery date`
                : 'Every open order is inside its delivery date',
            tone: props.tiles.late_orders > 0 ? 'danger' : 'neutral',
            href: '/sales-orders?late=1',
        });
    }

    if (a.on_time) {
        tiles.push({
            key: 'on_time',
            label: `On-time delivery, ${windowLabel.value}`,
            value: a.on_time.pct === null ? '—' : pct(a.on_time.pct, 0),
            sub: a.on_time.pct === null
                ? 'No pieces delivered in the window'
                : `of ${compact(a.on_time.pieces)} pcs delivered on or before the promised date`,
            delta: { value: a.on_time.delta_pts, unit: ' pts', vs: previousLabel.value, upIsGood: true },
            tone: a.on_time.pct !== null && a.on_time.pct < 80 ? 'warning' : 'neutral',
            href: '/delivery-challans',
        });
    }

    if (a.orders) {
        tiles.push({
            key: 'orders',
            label: `Orders received, ${windowLabel.value}`,
            value: pcs(a.orders.count),
            sub: `${money(a.orders.value)} in order value`,
            delta: { value: a.orders.delta_pct, unit: '%', vs: previousLabel.value, upIsGood: true },
            trend: a.orders.weekly.map((w) => w.count),
            href: '/sales-orders',
        });
    }

    if (a.delivered) {
        tiles.push({
            key: 'delivered',
            label: `Pieces delivered, ${windowLabel.value}`,
            value: compact(a.delivered.pieces),
            sub: a.delivered.pieces > 0 ? `${pcs(a.delivered.pieces)} pcs on issued delivery notes` : 'Nothing left the factory in the window',
            delta: { value: a.delivered.delta_pct, unit: '%', vs: previousLabel.value, upIsGood: true },
            trend: a.delivered.weekly.map((w) => w.pieces),
            href: '/delivery-challans',
        });
    }

    if (a.quotations) {
        const decided = a.quotations.won + a.quotations.lost;

        tiles.push({
            key: 'quotations',
            label: `Quotation win rate, last ${a.quotation_window_days} days`,
            value: a.quotations.win_rate === null ? '—' : pct(a.quotations.win_rate, 0),
            sub: decided > 0
                ? `${pcs(a.quotations.won)} won · ${pcs(a.quotations.lost)} lost · ${pcs(a.quotations.awaiting)} awaiting`
                : `No decisions yet · ${pcs(a.quotations.awaiting)} awaiting`,
            delta: { value: a.quotations.delta_pts, unit: ' pts', vs: `previous ${a.quotation_window_days} days`, upIsGood: true },
            href: '/quotations?status=sent',
        });
    }

    if (a.receivables) {
        tiles.push({
            key: 'receivables',
            label: 'Overdue receivables',
            value: money(a.receivables.overdue_amount),
            sub: a.receivables.overdue_count > 0
                ? `${pcs(a.receivables.overdue_count)} ${a.receivables.overdue_count === 1 ? 'invoice' : 'invoices'} past due · ${money(a.receivables.outstanding)} outstanding in all`
                : `Nothing past due · ${money(a.receivables.outstanding)} outstanding in all`,
            tone: a.receivables.overdue_amount > 0 ? 'warning' : 'neutral',
            href: '/sales-invoices?overdue=1',
        });
    }

    return tiles;
});

const position = computed(() => [
    { permission: 'sales_order.view_any', label: 'Open orders', value: pcs(props.tiles.open_orders), href: '/sales-orders' },
    { permission: 'job_card.view_any', label: 'Job cards on the floor', value: pcs(props.tiles.on_floor), href: '/job-cards' },
    // Waiting on material and artwork awaiting approval are not here: when either is above
    // zero it is a Needs-you chip, and a zero in a strip is a label with nothing to say.
    { permission: 'quotation.view_any', label: 'Quotations out', value: pcs(props.tiles.quotations_open), href: '/quotations?status=sent' },
    // F-12 — "Open job cards" read as "cards in production", so the two `completed` cards
    // inside it looked like a counting error. They are not: a completed card still has to be
    // closed, and closing it is somebody's job. The figure is named after what it counts, and
    // the status card below spells out the population.
    { permission: 'job_card.view_any', label: 'Job cards not yet closed', value: pcs(props.tiles.open_job_cards), href: '/job-cards?open=1' },
    // BR-47 — every other amount on this dashboard is labelled with a currency code; this one
    // passed the taka symbol, so the same figure read `৳ 1,234` here and `BDT 1,234` elsewhere.
    { permission: 'stock_lot.view_any', label: 'Stock value', value: money(props.tiles.stock_value), href: '/stock' },
].filter((tile) => can(tile.permission)));

/*
 * Orders in against pieces out, week by week. Two series that are the subject, so two
 * categorical hues from the validated order; a legend and a tooltip that reads both at
 * once; bars capped thin with a surface gap so neighbours separate without a stroke.
 */
const flowOption = computed(() => {
    const weeks = props.analytics.orders?.weekly ?? [];
    const delivered = props.analytics.delivered?.weekly ?? [];

    return {
        xAxis: { type: 'category', data: weeks.map((w) => tick(w.starts_on)) },
        yAxis: { type: 'value', axisLabel: { formatter: (v) => compact(v) } },
        grid: { bottom: 28 },
        tooltip: {
            formatter: (params) => {
                const week = weeks[params[0]?.dataIndex];
                const head = week ? `${date(week.starts_on)} to ${date(week.ends_on)}` : '';
                const rows = params.map((p) => `<div style="display:flex;justify-content:space-between;gap:16px"><span>${p.marker} ${p.seriesName}</span><strong>${pcs(p.value)} pcs</strong></div>`);

                return `<div style="color:${CHART.muted};margin-bottom:4px">${head}</div>${rows.join('')}`;
            },
        },
        series: [
            { name: 'Ordered', type: 'bar', data: weeks.map((w) => w.pieces), barMaxWidth: 18, barGap: '15%', itemStyle: { borderRadius: [4, 4, 0, 0] } },
            { name: 'Delivered', type: 'bar', data: delivered.map((w) => w.pieces), barMaxWidth: 18, itemStyle: { borderRadius: [4, 4, 0, 0] } },
        ],
    };
});

const flowTotals = computed(() => ({
    ordered: (props.analytics.orders?.weekly ?? []).reduce((s, w) => s + w.pieces, 0),
    delivered: (props.analytics.delivered?.weekly ?? []).reduce((s, w) => s + w.pieces, 0),
}));

/*
 * Pieces still owed, by the week they were promised for. One measure, so one hue; the
 * overdue bucket wears the status red because it is a state, not a week. Only the two
 * buckets that mean something today carry a label; the rest live in the tooltip.
 */
const outlook = computed(() => (props.analytics.outlook ?? []).map((b) => ({
    ...b,
    label: b.label ?? tick(b.starts_on),
})));

// Horizontal: eight named buckets do not fit as ticks across a third of the screen, and
// "Overdue" has to be read, not guessed. Inverse so the overdue bucket is the first row.
const outlookOption = computed(() => ({
    yAxis: { type: 'category', inverse: true, data: outlook.value.map((b) => b.label), axisLabel: { interval: 0, fontSize: 11 } },
    xAxis: { type: 'value', splitNumber: 2, axisLabel: { formatter: (v) => compact(v) } },
    grid: { left: 4, right: 36, top: 8, bottom: 4 },
    legend: { show: false },
    tooltip: {
        formatter: (params) => {
            const bucket = outlook.value[params[0]?.dataIndex];

            if (!bucket) return '';

            return `<div style="color:${CHART.muted};margin-bottom:4px">${bucket.label}${bucket.starts_on && bucket.label !== 'This week' ? ` · week of ${date(bucket.starts_on)}` : ''}</div>`
                + `<div><strong>${pcs(bucket.pieces)} pcs</strong> on ${pcs(bucket.lines)} ${bucket.lines === 1 ? 'line' : 'lines'}</div>`;
        },
    },
    series: [{
        name: 'Pieces owed',
        type: 'bar',
        barMaxWidth: 18,
        data: outlook.value.map((b, index) => ({
            value: b.pieces,
            itemStyle: { color: b.key === 'overdue' ? CHART.critical : CHART.series[0], borderRadius: [0, 4, 4, 0] },
            label: index < 2 && b.pieces > 0 ? { show: true, position: 'right', formatter: () => compact(b.pieces), color: CHART.ink, fontSize: 11 } : { show: false },
        })),
    }],
}));

const outlookSummary = computed(() => outlook.value.map((b) => `${b.label}: ${pcs(b.pieces)} pcs`).join(', '));

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

    // "Lines", said out loud: the late-orders figure counts orders by their delivery date,
    // this counts order lines by their promised date, and the two can legitimately differ.
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
 * The promised date says how late or how soon, not only when. A line that has been
 * delivered in full keeps a plain date — being past it is history, not a problem.
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
 * One stacked bar, because the proportion is the information. The colours follow the
 * status badges used everywhere else, so indigo is still "in production" here.
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

const quality = computed(() => props.analytics.quality ?? null);

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
 * full height of its neighbours. Each empty card folds into one line here; the card itself
 * appears only when it has rows to show.
 */
const showArtworkQueue = computed(() => can('artwork.view_any') && props.artworkQueue.length > 0);
const showMachineLoad = computed(() => canAny('production_plan.view_any', 'job_card.view_any') && loadByMachine.value.length > 0);
const showCertificates = computed(() => canAny('certification.view_any', 'coc.view_any') && props.expiringCertificates.length > 0);

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
        <template #subtitle>What is waiting on you, and how the month is going</template>

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

        <!--
            What is waiting on this user, as one row of chips: a count and a name each. Every
            entry is gated by the permission that would let them act on it, and an empty queue
            is hidden rather than shown as a row of zeros. It used to be a row of cards that
            gave one item the whole width and the figures below a third of the screen.
        -->
        <section v-if="queue.length" class="mb-4 flex flex-wrap items-center gap-x-2 gap-y-2" aria-label="Needs you">
            <h2 class="mr-1 text-xs font-semibold tracking-wider text-ink-400 uppercase">Needs you</h2>
            <Link
                v-for="entry in queue"
                :key="entry.key"
                :href="entry.href"
                :title="entry.hint"
                class="group inline-flex min-h-9 items-center gap-2 rounded-full border bg-white py-1 pr-3 pl-1.5 text-sm font-medium transition hover:shadow-sm focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                :class="entry.tone === 'danger'
                    ? 'border-rose-200 text-rose-900 hover:border-rose-300'
                    : 'border-amber-200 text-amber-950 hover:border-amber-300'"
            >
                <span
                    class="flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 text-xs font-semibold tnum"
                    :class="entry.tone === 'danger' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-900'"
                >
                    {{ pcs(entry.count) }}
                </span>
                {{ entry.label }}
                <Icon name="right" size="size-3.5" class="text-ink-400 transition group-hover:translate-x-0.5 group-hover:text-ink-600" />
            </Link>
        </section>

        <div class="space-y-4">
            <!-- The month in figures. -->
            <section v-if="kpis.length" aria-label="How the month is going" class="grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-6">
                <StatTile
                    v-for="tile in kpis"
                    :key="tile.key"
                    :label="tile.label"
                    :value="tile.value"
                    :sub="tile.sub"
                    :delta="tile.delta"
                    :trend="tile.trend"
                    :tone="tile.tone"
                    :href="tile.href"
                />
            </section>

            <!-- Where the book stands right now. One strip, still links, still filtered. -->
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

            <!-- The two charts: what came in against what went out, and what is owed by week. -->
            <div v-if="analytics.orders || analytics.outlook" class="grid gap-4 lg:grid-cols-3">
                <Card
                    v-if="analytics.orders && analytics.delivered"
                    class="lg:col-span-2"
                    title="Orders in, pieces out"
                    :subtitle="`Pieces ordered against pieces delivered, by week, last ${analytics.weeks} weeks`"
                >
                    <Chart
                        :option="flowOption"
                        :height="240"
                        :label="`Pieces ordered against pieces delivered by week over the last ${analytics.weeks} weeks: ${pcs(flowTotals.ordered)} ordered, ${pcs(flowTotals.delivered)} delivered`"
                    />
                    <p class="mt-2 text-xs text-ink-500">
                        Over the period: <span class="font-medium tnum text-ink-700">{{ pcs(flowTotals.ordered) }} pcs</span> ordered,
                        <span class="font-medium tnum text-ink-700">{{ pcs(flowTotals.delivered) }} pcs</span> delivered.
                    </p>
                </Card>

                <Card
                    v-if="analytics.outlook"
                    title="Delivery outlook"
                    subtitle="Pieces still owed, by the week they were promised for"
                >
                    <Chart :option="outlookOption" :height="248" :label="`Pieces owed by promised week: ${outlookSummary}`" />
                    <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-500">
                        <span v-if="outlook[0]?.pieces > 0" class="flex items-center gap-1.5">
                            <span class="size-2.5 rounded-sm bg-rose-600" aria-hidden="true" />
                            <span class="font-medium tnum text-ink-700">{{ pcs(outlook[0].pieces) }} pcs</span> already overdue
                        </span>
                        <span v-if="outlook[1]" class="flex items-center gap-1.5">
                            <span class="size-2.5 rounded-sm bg-teal-600" aria-hidden="true" />
                            <span class="font-medium tnum text-ink-700">{{ pcs(outlook[1].pieces) }} pcs</span> due this week
                        </span>
                    </p>
                </Card>
            </div>

            <!-- Side by side only from 1536 px: at 1280 the order book lost its last two columns. -->
            <div class="grid gap-4 2xl:grid-cols-3">
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

                    <!-- Quality, as three figures: how much was inspected, how much passed first time, how dirty it was. -->
                    <Card v-if="quality" title="Quality" :subtitle="`Inspections in the ${windowLabel}`">
                        <p v-if="quality.inspections === 0" class="text-sm text-ink-500">No inspection was recorded in the window.</p>
                        <dl v-else class="grid grid-cols-3 gap-3">
                            <div>
                                <dt class="text-xs text-ink-600">Inspected</dt>
                                <dd class="mt-0.5 text-xl font-semibold text-ink-900">{{ pcs(quality.inspections) }}</dd>
                                <dd class="text-xs text-ink-500">{{ quality.inspections === 1 ? 'lot' : 'lots' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-ink-600">Accepted first time</dt>
                                <dd class="mt-0.5 text-xl font-semibold" :class="quality.accepted_pct < 90 ? 'text-amber-700' : 'text-ink-900'">{{ pct(quality.accepted_pct, 0) }}</dd>
                                <dd v-if="quality.delta_pts !== null" class="text-xs" :class="quality.delta_pts >= 0 ? 'text-emerald-700' : 'text-rose-700'">
                                    {{ quality.delta_pts > 0 ? '+' : '' }}{{ number(quality.delta_pts, 1) }} pts vs {{ previousLabel }}
                                </dd>
                                <dd v-else class="text-xs text-ink-500">No earlier period</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-ink-600">Defects per 100 (DHU)</dt>
                                <dd class="mt-0.5 text-xl font-semibold text-ink-900">{{ quality.avg_dhu === null ? '—' : number(quality.avg_dhu, 2) }}</dd>
                                <dd class="text-xs text-ink-500">average across lots</dd>
                            </div>
                        </dl>
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

        </div>
    </AppLayout>
</template>
