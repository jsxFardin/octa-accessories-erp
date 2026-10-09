<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import ActivationCard from '@/Components/MasterData/ActivationCard.vue';
import { date, datetime, money, pcs, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ item: Object, stock: Array, lots: Array, labels: { type: Object, default: () => ({}) }, activation: { type: Array, default: () => [] }, transitions: { type: Array, default: () => [] }, attributeDefinitions: { type: Array, default: () => [] } });

/** The fixed vocabularies, worded. Never the raw key. */
function label(list, value) {
    return props.labels?.[list]?.[value] ?? titleCase(value ?? '');
}

/** The family's attributes in their own order and words; anything else on the bag after them. */
const attributeRows = (() => {
    const values = props.item.attributes ?? {};
    const defined = props.attributeDefinitions.map((definition) => [definition.unit ? `${definition.label} (${definition.unit})` : definition.label, values[definition.key]]);
    const known = new Set(props.attributeDefinitions.map((definition) => definition.key));
    const extra = Object.entries(values).filter(([key]) => !known.has(key)).map(([key, value]) => [titleCase(key), value]);

    return [...defined, ...extra].filter(([, value]) => value !== null && value !== undefined && value !== '');
})();

</script>

<template>
    <AppLayout>
        <Head :title="item.code" />

        <template #title>{{ item.code }} · {{ item.name }}</template>
        <template #subtitle>
            {{ label('itemTypes', item.item_type) }} · {{ label('makeOrBuy', item.make_or_buy) }} · stock unit {{ item.base_uom?.code }}
            <span v-if="item.family"> · {{ item.family.code }} {{ item.family.name }}</span>
        </template>

        <template #actions>
            <Badge :status="item.status" />
            <Button v-if="item.product && can('product.view')" size="sm" variant="ghost" :href="`/products/${item.product.id}`">Product page</Button>
            <Button v-if="can('item.update')" size="sm" :href="`/items/${item.id}/edit`">Edit</Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <ActivationCard class="lg:col-span-3" :steps="activation" :transitions="transitions" :status="item.status" :code="item.code" :action="`/items/${item.id}/transition`" />

            <Card title="Classification" rule="IM-1" class="lg:col-span-1">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-ink-500">Family</dt><dd class="text-right">{{ item.family ? `${item.family.code} ${item.family.name}` : '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-ink-500">Group</dt><dd class="text-right">{{ item.group?.name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-ink-500">Category</dt><dd class="text-right">{{ item.category?.name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-ink-500">Garment type</dt><dd>{{ item.garment_type ? label('garmentTypes', item.garment_type) : '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-ink-500">Material base</dt><dd>{{ item.material_base ? label('materialBases', item.material_base) : '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-ink-500">Scope</dt><dd>{{ label('specScopes', item.spec_scope) }}<span v-if="item.customer"> · {{ item.customer.name }}</span></dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-ink-500">Varies by</dt><dd>{{ (item.variant_axes ?? []).length ? item.variant_axes.map((axis) => label('variantAxes', axis)).join(', ') : 'Nothing' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-ink-500">Order unit</dt><dd>{{ item.order_uom?.code ?? item.base_uom?.code }}</dd></div>
                    <div v-if="item.pack_pcs_per_inner || item.pack_inners_per_carton" class="flex justify-between gap-4"><dt class="text-ink-500">Pack</dt><dd class="tnum">{{ item.pack_pcs_per_inner ?? '—' }} / inner · {{ item.pack_inners_per_carton ?? '—' }} inners / carton</dd></div>
                    <div v-if="item.item_type === 'tool'" class="flex justify-between gap-4"><dt class="text-ink-500">Tool</dt><dd>{{ item.tool_cavities ?? '—' }} cavities · {{ item.tool_owner?.name ?? 'factory-owned' }}</dd></div>
                    <div v-if="item.item_type === 'service'" class="flex justify-between gap-4"><dt class="text-ink-500">Charged</dt><dd>{{ item.service_charge_basis ? label('chargeBases', item.service_charge_basis) : '—' }}</dd></div>
                    <template v-if="attributeRows.length">
                        <div v-for="[label, value] in attributeRows" :key="label" class="flex justify-between gap-4">
                            <dt class="text-ink-500">{{ label }}</dt><dd class="text-right">{{ value === true ? 'Yes' : value === false ? 'No' : value }}</dd>
                        </div>
                    </template>
                </dl>
            </Card>

            <Card title="Master data" class="lg:col-span-1">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-500">Standard rate</dt><dd class="tnum">{{ money(item.std_rate) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Weighted average</dt><dd class="tnum font-medium">{{ money(item.avg_rate) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Valuation</dt><dd>{{ label('valuationMethods', item.valuation_method) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Reorder level / max</dt><dd class="tnum">{{ qty(item.reorder_level) }} / {{ item.max_stock_qty ? qty(item.max_stock_qty) : '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Standard wastage</dt><dd class="tnum">{{ item.standard_wastage_pct }}%</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Default warehouse</dt><dd>{{ item.default_warehouse?.name ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Min order / multiple</dt><dd class="tnum">{{ qty(item.min_order_qty) }} / {{ qty(item.order_multiple) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Safety days</dt><dd class="tnum">{{ item.safety_days }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Ink lay g/m²</dt><dd class="tnum">{{ item.ink_lay_gsm ?? '—' }}</dd></div>
                    <div class="flex gap-1 pt-1">
                        <Badge v-if="item.is_shade_critical" tone="warning" label="Shade critical" />
                        <Badge v-if="item.has_expiry" tone="info" :label="`Expires after ${item.shelf_life_days}d`" />
                        <Badge v-if="item.is_lot_tracked" tone="neutral" label="Lot tracked" />
                    </div>
                </dl>
            </Card>

            <Card class="lg:col-span-1" title="Stock by warehouse" rule="BR-24" subtitle="Some warehouses hold stock the material plan does not count, such as quarantine">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="row in stock" :key="row.warehouse_code" class="flex items-center justify-between py-2">
                        <span>
                            <span class="font-medium">{{ row.warehouse_code }}</span>
                            <span class="text-ink-500"> {{ row.warehouse_name }}</span>
                            <Badge v-if="!row.is_nettable" tone="neutral" label="non-net" class="ml-1" />
                        </span>
                        <span class="tnum font-medium">{{ qty(row.balance_qty) }}</span>
                    </li>
                    <li v-if="stock.length === 0" class="py-6 text-center text-ink-500">No stock on hand.</li>
                </ul>
            </Card>

            <Card class="lg:col-span-3" title="Open lots" rule="BR-37 · I5" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'lot_no', label: 'Lot' },
                        { key: 'shade_code', label: 'Shade' },
                        { key: 'balance_qty', label: 'Balance', align: 'right' },
                        { key: 'received_on', label: 'Received' },
                        { key: 'expiry_date', label: 'Expiry' },
                        { key: 'cert', label: 'Claim' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="lots"
                    row-key="id"
                    :row-href="(row) => `/lots/${row.id}`"
                    empty="No open lots."
                    dense
                >
                    <template #cell:lot_no="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                    <template #cell:balance_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:received_on="{ value }">{{ date(value) }}</template>
                    <template #cell:expiry_date="{ value }">{{ value ? date(value) : '—' }}</template>
                    <template #cell:cert="{ row }">
                        <Badge v-if="row.cert_scheme" tone="success" :label="`${row.cert_scheme} ${row.cert_claim_pct}%`" />
                        <span v-else class="text-ink-400">—</span>
                    </template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
