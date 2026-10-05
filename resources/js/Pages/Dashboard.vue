<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, money, pcs, pct } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import { can, canAny } from '@/plugins/permissions';

const props = defineProps({
    queue: { type: Array, default: () => [] },
    /** What a new installation still has to do, or null once it is all done. */
    setup: { type: Object, default: null },
    tiles: { type: Object, required: true },
    orderBook: { type: Array, default: () => [] },
    jobCardsByStatus: { type: Array, default: () => [] },
    artworkQueue: { type: Array, default: () => [] },
    expiringCertificates: { type: Array, default: () => [] },
    machineLoad: { type: Array, default: () => [] },
});

// Each href lands on the list *already narrowed to the number on the tile* — "Late orders: 14"
// opening 400 unfiltered rows made the tile a decoration. Tiles whose count spans several
// statuses (open orders, on the floor) link to the bare list because the filter bar is
// single-status; the WorkQueue above stays the precise surface.
//
// A tile is shown only to someone who may open the list behind it. Everyone used to get all
// eight, so a store keeper's first screen led with quotations out and artwork awaiting approval
// — numbers they could do nothing about, on links that ended in "not allowed".
const tiles = computed(() => [
    { permission: 'sales_order.view_any', label: 'Open orders', value: pcs(props.tiles.open_orders), href: '/sales-orders', tone: 'brand' },
    { permission: 'sales_order.view_any', label: 'Late orders', value: pcs(props.tiles.late_orders), href: '/sales-orders?late=1', tone: props.tiles.late_orders > 0 ? 'danger' : 'muted' },
    { permission: 'job_card.view_any', label: 'Job cards on the floor', value: pcs(props.tiles.on_floor), href: '/job-cards', tone: 'brand' },
    { permission: 'job_card.view_any', label: 'Waiting on material', value: pcs(props.tiles.material_pending), href: '/job-cards?status=material_pending', tone: props.tiles.material_pending > 0 ? 'warning' : 'muted' },
    { permission: 'artwork.view_any', label: 'Artwork awaiting approval', value: pcs(props.tiles.artwork_pending), href: '/artworks?state=awaiting_approval', tone: props.tiles.artwork_pending > 0 ? 'warning' : 'muted' },
    { permission: 'quotation.view_any', label: 'Quotations out', value: pcs(props.tiles.quotations_open), href: '/quotations?status=sent', tone: 'muted' },
    // BR-47 — every other amount on this dashboard is labelled with a currency code; this one
    // passed the taka symbol, so the same figure read `৳ 1,234` here and `BDT 1,234` elsewhere.
    { permission: 'stock_lot.view_any', label: 'Stock value', value: money(props.tiles.stock_value), href: '/stock', tone: 'muted' },
    // F-12 — "Open job cards" read as "cards in production", so the two `completed` cards
    // inside it looked like a counting error. They are not: a completed card still has to be
    // closed, and closing it is somebody's job. The tile is named after what it counts.
    { permission: 'job_card.view_any', label: 'Job cards not yet closed', value: pcs(props.tiles.open_job_cards), href: '/job-cards?open=1', tone: 'muted', hint: 'Everything except closed and cancelled — a completed card still needs closing.' },
].filter((tile) => can(tile.permission)));

const TONES = {
    brand: 'text-brand-700',
    danger: 'text-rose-600',
    warning: 'text-amber-600',
    muted: 'text-ink-900',
};

const orderBookColumns = [
    { key: 'so_number', label: 'Order' },
    { key: 'customer_name', label: 'Customer' },
    { key: 'product_code', label: 'Product' },
    { key: 'ordered_qty', label: 'Ordered', align: 'right' },
    { key: 'delivered_qty', label: 'Delivered', align: 'right' },
    { key: 'delivered_pct', label: '%', align: 'right', width: '5rem' },
    { key: 'promised_date', label: 'Promised' },
    { key: 'line_status', label: 'Status' },
];

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

const loadByMachine = computed(() => {
    const grouped = {};

    for (const row of props.machineLoad) {
        grouped[row.machine_code] ??= { machine: row.machine_code, minutes: 0, operations: 0 };
        grouped[row.machine_code].minutes += Number(row.load_minutes ?? 0);
        grouped[row.machine_code].operations += Number(row.operation_count ?? 0);
    }

    return Object.values(grouped).sort((a, b) => b.minutes - a.minutes).slice(0, 8);
});
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

            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
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
            <!-- Tiles -->
            <!--
                Four across until the screen is genuinely wide. Eight across at 1280 px left each
                tile about 120 px: the labels were cut to "Job cards o…" and "Job cards n…",
                which are two different tiles, and the stock value ran out of its box.
            -->
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 2xl:grid-cols-8">
                <Link
                    v-for="tile in tiles"
                    :key="tile.label"
                    :href="tile.href"
                    class="group rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition hover:-translate-y-px hover:border-brand-300 hover:shadow-md focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                >
                    <p class="flex items-start justify-between gap-1 text-xs leading-snug text-ink-600">
                        <span class="min-w-0">{{ tile.label }}</span>
                        <!-- The chevron is the promise: this number opens the filtered list behind it. -->
                        <Icon name="right" size="size-3" class="mt-0.5 shrink-0 text-ink-400 transition group-hover:translate-x-0.5 group-hover:text-brand-500" />
                    </p>
                    <p class="mt-1 text-xl font-semibold break-words tnum" :class="TONES[tile.tone]">{{ tile.value }}</p>
                    <!-- The explanation used to live in a hover-only title attribute. -->
                    <p v-if="tile.hint" class="mt-1 text-xs leading-snug text-ink-500">{{ tile.hint }}</p>
                </Link>
            </div>

            <!-- Side by side only from 1536 px: at 1280 the order book lost its last two columns. -->
            <div class="grid gap-4 2xl:grid-cols-3">
                <!-- Order book -->
                <Card
                    v-if="can('sales_order.view_any')"
                    class="2xl:col-span-2"
                    title="Order book"
                    subtitle="Open lines with delivery progress"
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
                        <template #cell:delivered_pct="{ value }">{{ pct(value, 0) }}</template>
                        <template #cell:promised_date="{ value }">{{ date(value) }}</template>
                        <template #cell:line_status="{ value }"><Badge :status="value" /></template>
                    </DataTable>
                </Card>

                <div class="space-y-4">
                    <!-- Gate 1 queue -->
                    <Card
                        v-if="can('artwork.view_any')"
                        title="Awaiting customer approval"
                        subtitle="Nothing downstream may run against these"
                        rule="Gate 1 · A2"
                    >
                        <p v-if="artworkQueue.length === 0" class="text-sm text-ink-500">
                            No artwork is waiting on a signature.
                        </p>
                        <ul v-else class="divide-y divide-slate-100 text-sm">
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

                    <!--
                        F-12 — the same population as the "not yet closed" tile above, and
                        titled so. It used to count every card ever raised while sitting under
                        a tile that counted only open ones; they agreed only for as long as
                        nothing had been closed.
                    -->
                    <Card v-if="can('job_card.view_any')" title="Open job cards by status" subtitle="Closed and cancelled cards are not counted here.">
                        <p v-if="jobCardsByStatus.length === 0" class="text-sm text-ink-500">No open job cards.</p>
                        <ul v-else class="space-y-1.5">
                            <li v-for="row in jobCardsByStatus" :key="row.status" class="flex items-center justify-between gap-2">
                                <Badge :status="row.status" />
                                <span class="text-sm font-medium tnum text-ink-700">{{ pcs(row.count) }}</span>
                            </li>
                        </ul>
                    </Card>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- Machine load -->
                <Card v-if="canAny('production_plan.view_any', 'job_card.view_any')" title="Scheduled machine load" subtitle="Next 7 days" rule="BR-27">
                    <p v-if="loadByMachine.length === 0" class="text-sm text-ink-500">Nothing scheduled.</p>
                    <ul v-else class="space-y-2">
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
                    v-if="canAny('certification.view_any', 'coc.view_any')"
                    title="Certificates expiring"
                    subtitle="A shipment cannot claim a scheme whose certificate has lapsed"
                    rule="Gate 2 · BR-43"
                >
                    <p v-if="expiringCertificates.length === 0" class="text-sm text-ink-500">
                        Every certificate is current.
                    </p>
                    <ul v-else class="divide-y divide-slate-100 text-sm">
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
