<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, money, pcs, pct } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    tiles: { type: Object, required: true },
    orderBook: { type: Array, default: () => [] },
    jobCardsByStatus: { type: Array, default: () => [] },
    artworkQueue: { type: Array, default: () => [] },
    expiringCertificates: { type: Array, default: () => [] },
    machineLoad: { type: Array, default: () => [] },
});

const tiles = computed(() => [
    { label: 'Open orders', value: pcs(props.tiles.open_orders), href: '/sales-orders', tone: 'brand' },
    { label: 'Late orders', value: pcs(props.tiles.late_orders), href: '/sales-orders', tone: props.tiles.late_orders > 0 ? 'danger' : 'muted' },
    { label: 'Job cards on the floor', value: pcs(props.tiles.on_floor), href: '/job-cards', tone: 'brand' },
    { label: 'Waiting on material', value: pcs(props.tiles.material_pending), href: '/job-cards', tone: props.tiles.material_pending > 0 ? 'warning' : 'muted' },
    { label: 'Artwork awaiting approval', value: pcs(props.tiles.artwork_pending), href: '/artworks', tone: props.tiles.artwork_pending > 0 ? 'warning' : 'muted' },
    { label: 'Quotations out', value: pcs(props.tiles.quotations_open), href: '/quotations', tone: 'muted' },
    { label: 'Stock value', value: money(props.tiles.stock_value, '৳'), href: '/stock', tone: 'muted' },
    { label: 'Open job cards', value: pcs(props.tiles.open_job_cards), href: '/job-cards', tone: 'muted' },
]);

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
        <template #subtitle>Order book, floor status and the two gates, live</template>

        <div class="space-y-4">
            <!-- Tiles -->
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
                <Link
                    v-for="tile in tiles"
                    :key="tile.label"
                    :href="tile.href"
                    class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition hover:border-brand-300 hover:shadow"
                >
                    <p class="truncate text-[11px] text-ink-500">{{ tile.label }}</p>
                    <p class="mt-1 text-xl font-semibold tnum" :class="TONES[tile.tone]">{{ tile.value }}</p>
                </Link>
            </div>

            <div class="grid gap-4 xl:grid-cols-3">
                <!-- Order book -->
                <Card
                    class="xl:col-span-2"
                    title="Order book"
                    subtitle="Open lines with delivery progress"
                                        :padded="false"
                >
                    <DataTable :columns="orderBookColumns" :rows="orderBook" row-key="sales_order_line_id" dense
                               empty="No open order lines.">
                        <template #cell:so_number="{ row }">
                            <Link :href="`/sales-orders/${row.sales_order_id}`" class="font-medium text-brand-700">
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

                    <!-- Job cards by status -->
                    <Card title="Job cards by status">
                        <p v-if="jobCardsByStatus.length === 0" class="text-sm text-ink-500">No job cards yet.</p>
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
                <Card title="Scheduled machine load" subtitle="Next 7 days" rule="BR-27">
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
