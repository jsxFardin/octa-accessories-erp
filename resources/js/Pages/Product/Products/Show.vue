<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, mm, pcs, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    product: { type: Object, required: true },
    readiness: { type: Object, required: true },
    specs: { type: Array, default: () => [] },
    artworks: { type: Array, default: () => [] },
    boms: { type: Array, default: () => [] },
    currentSpecId: { type: Number, default: null },
    options: { type: Object, default: () => ({}) },
});

const currentSpec = computed(() => props.specs.find((s) => s.status === 'current') ?? null);

function makeCurrent(spec) {
    router.post(`/specs/${spec.id}/make-current`, {}, { preserveScroll: true });
}

function activateBom(bom) {
    router.post(`/boms/${bom.id}/activate`, {}, { preserveScroll: true });
}

const bomColumns = [
    { key: 'item', label: 'Item' },
    { key: 'qty_per_base', label: 'Qty / base', align: 'right' },
    { key: 'uom', label: 'UoM' },
    { key: 'wastage_pct', label: 'Wastage %', align: 'right' },
    { key: 'colour_index', label: 'Colour', align: 'center' },
    { key: 'formula_ref', label: 'Rule' },
];
</script>

<template>
    <AppLayout>
        <Head :title="product.code" />

        <template #title>{{ product.code }} · {{ product.name }}</template>
        <template #subtitle>
            <Link v-if="product.customer" :href="`/customers/${product.customer.id}`" class="hover:underline">
                {{ product.customer.name }}
            </Link>
            · {{ titleCase(product.product_type) }}
            <span v-if="product.customer_style_ref"> · style {{ product.customer_style_ref }}</span>
        </template>

        <template #actions>
            <Badge :status="product.status" />
            <Button v-if="can('product.update')" size="sm" :href="`/products/${product.id}/edit`">Edit</Button>
        </template>

        <div class="space-y-4">
            <!-- S3 readiness: what has to be true before this product can be ordered -->
            <Card title="Order readiness" rule="S3 · Gate 1" subtitle="A sales order line cannot be confirmed until spec and artwork are in place">
                <div class="grid gap-2 sm:grid-cols-3">
                    <div
                        v-for="[key, label, note] in [
                            ['spec', 'Current spec (P2)', 'Exactly one spec is current at a time'],
                            ['artwork', 'Approved artwork (A2)', 'The version production is welded to'],
                            ['bom', 'Active BOM (PD-3)', 'Needed to release a job card, not to confirm an order'],
                        ]"
                        :key="key"
                        class="rounded-md border px-3 py-2"
                        :class="readiness[key] ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium" :class="readiness[key] ? 'text-emerald-900' : 'text-amber-900'">
                                {{ label }}
                            </span>
                            <Badge :tone="readiness[key] ? 'success' : 'warning'" :label="readiness[key] ? 'Yes' : 'Missing'" />
                        </div>
                        <p class="mt-0.5 text-xs" :class="readiness[key] ? 'text-emerald-800' : 'text-amber-800'">{{ note }}</p>
                    </div>
                </div>
            </Card>

            <div class="grid gap-4 xl:grid-cols-3">
                <!-- Spec versions with their derived geometry -->
                <Card class="xl:col-span-2" title="Specifications" rule="P2 · P3" subtitle="Immutable once referenced; a change is a new version" :padded="false">
                    <ul class="divide-y divide-slate-100">
                        <li v-for="spec in specs" :key="spec.id" class="p-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold">v{{ spec.version_no }}</span>
                                        <Badge :status="spec.status" />
                                    </div>

                                    <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                                        <div><dt class="text-ink-500">Label</dt><dd class="tnum">{{ mm(spec.label_width_mm) }} × {{ mm(spec.label_height_mm) }}</dd></div>
                                        <div><dt class="text-ink-500">Web</dt><dd class="tnum">{{ spec.web_width_mm ? mm(spec.web_width_mm) : '—' }}</dd></div>
                                        <div><dt class="text-ink-500">Cut</dt><dd>{{ titleCase(spec.cut_type) || '—' }}</dd></div>
                                        <div><dt class="text-ink-500">Fold</dt><dd>{{ titleCase(spec.fold_type) || '—' }}</dd></div>
                                        <div><dt class="text-ink-500">Colours</dt><dd class="tnum">{{ spec.colours }}</dd></div>
                                        <div><dt class="text-ink-500">Bundle</dt><dd class="tnum">{{ spec.bundle_size }} / {{ spec.bundles_per_carton }}</dd></div>
                                        <div><dt class="text-ink-500">GSM</dt><dd class="tnum">{{ spec.fabric_gsm ?? '—' }}</dd></div>
                                        <div><dt class="text-ink-500">Coverage</dt><dd class="tnum">{{ spec.coverage_pct }}%</dd></div>
                                    </dl>

                                    <!-- BR-4/5/6 shown beside the inputs that produced them -->
                                    <div class="mt-2 flex flex-wrap gap-3 rounded bg-slate-50 px-2 py-1.5 text-xs">
                                        <span><span class="text-ink-500">pitch</span> <span class="tnum font-medium">{{ spec.derived.pitch_mm }} mm</span> <span class="font-mono text-[10px] text-ink-400">BR-4</span></span>
                                        <span><span class="text-ink-500">labels/m</span> <span class="tnum font-medium">{{ spec.derived.labels_per_metre }}</span> <span class="font-mono text-[10px] text-ink-400">BR-4</span></span>
                                        <span><span class="text-ink-500">ends</span> <span class="tnum font-medium">{{ spec.ends ?? spec.derived.suggested_ends }}</span> <span class="font-mono text-[10px] text-ink-400">BR-5</span></span>
                                        <span><span class="text-ink-500">labels/web m</span> <span class="tnum font-medium">{{ spec.derived.labels_per_web_metre }}</span> <span class="font-mono text-[10px] text-ink-400">BR-6</span></span>
                                    </div>
                                </div>

                                <Button
                                    v-if="spec.status !== 'current' && can('product_spec.make_current')"
                                    size="sm"
                                    @click="makeCurrent(spec)"
                                >
                                    Make current
                                </Button>
                            </div>
                        </li>

                        <li v-if="specs.length === 0" class="p-6 text-center text-sm text-ink-500">
                            No spec yet. This product cannot be quoted or ordered until one exists.
                        </li>
                    </ul>
                </Card>

                <Card title="Artwork" rule="Gate 1" :padded="false">
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="artwork in artworks" :key="artwork.id" class="p-3">
                            <Link :href="`/artworks/${artwork.id}`" class="font-medium text-brand-700 hover:underline">
                                {{ artwork.code }}
                            </Link>
                            <p class="text-xs text-ink-500">{{ artwork.title }}</p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <Badge
                                    v-for="version in artwork.versions"
                                    :key="version.id"
                                    :status="version.status"
                                    :label="`v${version.version_no}`"
                                />
                            </div>
                        </li>
                        <li v-if="artworks.length === 0" class="p-6 text-center text-ink-500">No artwork yet.</li>
                    </ul>
                </Card>
            </div>

            <Card title="Bills of material" rule="PD-3 · BR-1" subtitle="Quantities are per base quantity — 1000 pieces by default" :padded="false">
                <div v-for="bom in boms" :key="bom.id" class="border-b border-slate-100 last:border-0">
                    <div class="flex flex-wrap items-center justify-between gap-2 bg-slate-50/60 px-3 py-2">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold">v{{ bom.version_no }}</span>
                            <Badge :status="bom.status" />
                            <span class="text-xs text-ink-500">per {{ pcs(bom.base_qty) }} pcs</span>
                        </div>
                        <Button v-if="bom.status !== 'active' && can('bom.activate')" size="sm" @click="activateBom(bom)">
                            Activate
                        </Button>
                    </div>

                    <DataTable :columns="bomColumns" :rows="bom.lines" row-key="id" dense empty="No lines.">
                        <template #cell:item="{ row }">
                            <span class="font-medium">{{ row.item?.code }}</span>
                            <span class="text-ink-500"> {{ row.item?.name }}</span>
                        </template>
                        <template #cell:qty_per_base="{ value }">{{ qty(value) }}</template>
                        <template #cell:colour_index="{ value }">{{ value ?? 'all' }}</template>
                        <template #cell:formula_ref="{ value }">
                            <span v-if="value" class="rounded bg-slate-100 px-1 font-mono text-[10px]">{{ value }}</span>
                            <span v-else class="text-ink-400">fixed</span>
                        </template>
                    </DataTable>
                </div>

                <p v-if="boms.length === 0" class="px-3 py-6 text-center text-sm text-ink-500">
                    No BOM yet. A job card cannot be released without an active one (J1).
                </p>
            </Card>
        </div>
    </AppLayout>
</template>
