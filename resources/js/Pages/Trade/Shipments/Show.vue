<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, money, qty, titleCase, todayIso } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    shipment: { type: Object, required: true },
    costs: { type: Array, default: () => [] },
    receipts: { type: Array, default: () => [] },
    allocations: { type: Array, default: () => [] },
    linkableReceipts: { type: Array, default: () => [] },
    costTypes: { type: Array, default: () => [] },
    allocableTypes: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    vendors: { type: Array, default: () => [] },
});

const NEXT = {
    draft: ['in_transit', 'cancelled'],
    in_transit: ['arrived', 'cancelled'],
    arrived: ['cleared', 'cancelled'],
    cleared: ['costed', 'closed'],
    costed: ['closed'],
};

const next = computed(() => NEXT[props.shipment.status] ?? []);

const addingCost = ref(false);
const linking = ref(false);
const allocating = ref(false);

const costForm = useForm({
    cost_type: 'freight',
    description: '',
    supplier_id: '',
    reference_no: '',
    incurred_on: todayIso(),
    currency_id: props.shipment.currency_id,
    exchange_rate: 1,
    amount: '',
    is_allocable: true,
});

const linkForm = useForm({ grn_id: '' });
const allocateForm = useForm({ basis: 'value' });

/** The three numbers this screen exists to reconcile. */
const allocable = computed(() =>
    props.costs.filter((cost) => cost.is_allocable).reduce((sum, cost) => sum + Number(cost.base_amount), 0));

const unallocated = computed(() => Math.round((allocable.value - Number(props.shipment.allocated_amount)) * 100) / 100);

const receiptLines = computed(() => new Set(props.allocations.map((row) => row.grn_number)).size);

function move(status) {
    router.post(`/import-shipments/${props.shipment.id}/transition`, { status }, { preserveScroll: true });
}

function submitCost() {
    costForm.post(`/import-shipments/${props.shipment.id}/costs`, {
        preserveScroll: true,
        onSuccess: () => {
            addingCost.value = false;
            costForm.reset('amount', 'description', 'reference_no');
        },
    });
}

function removeCost(id) {
    router.delete(`/import-shipments/${props.shipment.id}/costs/${id}`, { preserveScroll: true });
}

function link() {
    linkForm.post(`/import-shipments/${props.shipment.id}/receipts`, {
        preserveScroll: true,
        onSuccess: () => {
            linking.value = false;
            linkForm.reset();
        },
    });
}

function unlink(grnId) {
    router.delete(`/import-shipments/${props.shipment.id}/receipts/${grnId}`, { preserveScroll: true });
}

function allocate() {
    allocateForm.post(`/import-shipments/${props.shipment.id}/allocate`, {
        preserveScroll: true,
        onSuccess: () => (allocating.value = false),
    });
}

/** Allocation rows grouped by receipt line, which is how the arithmetic reads. */
const byLine = computed(() => {
    const lines = new Map();

    for (const row of props.allocations) {
        const key = `${row.grn_number}·${row.item_code}·${row.lot_no ?? ''}`;

        if (!lines.has(key)) {
            lines.set(key, {
                key,
                grn_number: row.grn_number,
                item_code: row.item_code,
                item_name: row.item_name,
                lot_no: row.lot_no,
                received_qty: row.received_qty,
                rate: row.rate,
                landed_rate: row.landed_rate,
                added: 0,
                parts: [],
            });
        }

        const line = lines.get(key);

        line.added += Number(row.amount);
        line.parts.push(`${titleCase(row.cost_type)} ${money(row.amount)}`);
    }

    return [...lines.values()];
});
</script>

<template>
    <AppLayout>
        <Head :title="shipment.number ?? 'Shipment'" />

        <template #title>{{ shipment.number }}</template>
        <template #subtitle>
            {{ shipment.supplier_name }} · {{ titleCase(shipment.mode) }}
            <template v-if="shipment.transport_doc_no"> · {{ shipment.transport_doc_no }}</template>
            <template v-if="shipment.lc_number"> · LC {{ shipment.lc_number }}</template>
        </template>

        <template #actions>
            <Badge :status="shipment.status" />
            <Button
                v-if="can('import_shipment.allocate')"
                size="sm"
                :variant="unallocated !== 0 && receipts.length ? 'primary' : 'secondary'"
                @click="allocating = true"
            >
                <Icon name="refresh" size="size-3.5" />
                Allocate costs
            </Button>
            <Button
                v-for="status in next"
                :key="status"
                size="sm"
                :variant="status === 'cancelled' ? 'danger' : 'secondary'"
                @click="move(status)"
            >
                {{ titleCase(status) }}
            </Button>
            <Button v-if="can('import_shipment.update')" size="sm" :href="`/import-shipments/${shipment.id}/edit`">Edit</Button>
        </template>

        <div class="space-y-4">
            <!--
                The reconciliation, stated rather than left to be worked out: costs recorded,
                costs pushed into stock, and the gap. A shipment sitting on a gap is a
                shipment whose stock is valued at the supplier's rate.
            -->
            <Card title="Cost position" rule="BR-36">
                <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm sm:grid-cols-5">
                    <div>
                        <dt class="text-ink-500">Goods value</dt>
                        <dd class="tnum font-medium">{{ money(shipment.goods_value) }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">Costs recorded</dt>
                        <dd class="tnum font-medium">{{ money(shipment.cost_total) }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">Of which allocable</dt>
                        <dd class="tnum font-medium">{{ money(allocable) }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">Pushed into stock</dt>
                        <dd class="tnum font-medium">{{ money(shipment.allocated_amount) }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">Not yet in stock</dt>
                        <dd class="tnum font-medium" :class="unallocated !== 0 ? 'text-amber-700' : 'text-emerald-700'">
                            {{ money(unallocated) }}
                        </dd>
                    </div>
                </dl>

                <p v-if="unallocated !== 0 && receipts.length" class="mt-3 text-xs text-amber-800">
                    {{ money(unallocated) }} of cost is recorded but not carried by any lot. Until it is allocated,
                    every margin calculated from this material is overstated.
                </p>
                <p v-else-if="!receipts.length" class="mt-3 text-xs text-ink-500">
                    No goods receipt is linked yet, so there is nothing for these costs to land on.
                </p>
            </Card>

            <Card title="Costs" subtitle="Freight, duty, C&F — each with the rate it was converted at" :padded="false">
                <template #actions>
                    <Button v-if="can('import_shipment.cost')" size="sm" @click="addingCost = true">Add cost</Button>
                </template>

                <DataTable
                    :columns="[
                        { key: 'cost_type', label: 'Type' },
                        { key: 'description', label: 'Description' },
                        { key: 'vendor', label: 'Vendor' },
                        { key: 'reference_no', label: 'Reference' },
                        { key: 'incurred_on', label: 'Date' },
                        { key: 'amount', label: 'Amount', align: 'right' },
                        { key: 'base_amount', label: 'In base', align: 'right' },
                        { key: 'is_allocable', label: 'In stock?' },
                        { key: 'remove', label: '', align: 'right' },
                    ]"
                    :rows="costs"
                    row-key="id"
                    empty="No costs recorded. Freight and duty usually arrive weeks after the goods."
                    dense
                >
                    <template #cell:cost_type="{ value }"><span class="font-medium">{{ titleCase(value) }}</span></template>
                    <template #cell:description="{ value }">{{ value ?? '—' }}</template>
                    <template #cell:vendor="{ value }">{{ value ?? '—' }}</template>
                    <template #cell:reference_no="{ value }">{{ value ?? '—' }}</template>
                    <template #cell:incurred_on="{ value }">{{ date(value) }}</template>
                    <template #cell:amount="{ row, value }">{{ money(value) }} {{ row.currency }}</template>
                    <template #cell:base_amount="{ value }">{{ money(value) }}</template>
                    <template #cell:is_allocable="{ value }">
                        <Badge :tone="value ? 'success' : 'neutral'" :label="value ? 'Allocable' : 'Period cost'" />
                    </template>
                    <template #cell:remove="{ row }">
                        <button
                            v-if="can('import_shipment.cost')"
                            class="text-xs text-rose-600 hover:underline"
                            @click.stop="removeCost(row.id)"
                        >
                            Remove
                        </button>
                    </template>
                </DataTable>
            </Card>

            <Card title="Goods receipts" subtitle="The receipts these costs are spread across" :padded="false">
                <template #actions>
                    <Button v-if="can('import_shipment.update') && linkableReceipts.length" size="sm" @click="linking = true">
                        Link receipt
                    </Button>
                </template>

                <DataTable
                    :columns="[
                        { key: 'number', label: 'GRN' },
                        { key: 'received_on', label: 'Received' },
                        { key: 'warehouse', label: 'Warehouse' },
                        { key: 'status', label: 'Status' },
                        { key: 'unlink', label: '', align: 'right' },
                    ]"
                    :rows="receipts"
                    row-key="id"
                    empty="No receipts linked yet."
                    dense
                >
                    <template #cell:number="{ row, value }">
                        <a :href="`/grns/${row.id}`" class="font-medium text-brand-700 hover:underline">{{ value }}</a>
                    </template>
                    <template #cell:received_on="{ value }">{{ date(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                    <template #cell:unlink="{ row }">
                        <button
                            v-if="can('import_shipment.update')"
                            class="text-xs text-rose-600 hover:underline"
                            @click.stop="unlink(row.id)"
                        >
                            Unlink
                        </button>
                    </template>
                </DataTable>
            </Card>

            <Card
                v-if="allocations.length"
                title="Landed cost by line"
                :subtitle="`Every lot's unit cost, and the bills behind it — ${receiptLines} receipt${receiptLines === 1 ? '' : 's'}`"
                :padded="false"
            >
                <DataTable
                    :columns="[
                        { key: 'grn_number', label: 'GRN' },
                        { key: 'item_code', label: 'Item' },
                        { key: 'lot_no', label: 'Lot' },
                        { key: 'received_qty', label: 'Qty', align: 'right' },
                        { key: 'rate', label: 'Supplier rate', align: 'right' },
                        { key: 'added', label: 'Cost added', align: 'right' },
                        { key: 'landed_rate', label: 'Landed rate', align: 'right' },
                        { key: 'parts', label: 'Made up of' },
                    ]"
                    :rows="byLine"
                    row-key="key"
                    empty="Nothing allocated yet."
                    dense
                >
                    <template #cell:item_code="{ row }">
                        <span class="font-medium">{{ row.item_code }}</span>
                        <span class="text-ink-500"> {{ row.item_name }}</span>
                    </template>
                    <template #cell:lot_no="{ value }">
                        <span v-if="value" class="font-mono text-xs">{{ value }}</span>
                        <span v-else class="text-ink-400">—</span>
                    </template>
                    <template #cell:received_qty="{ value }">{{ qty(value) }}</template>
                    <template #cell:rate="{ value }">{{ money(value) }}</template>
                    <template #cell:added="{ value }">{{ money(value) }}</template>
                    <template #cell:landed_rate="{ value }">
                        <span class="font-medium text-ink-900">{{ money(value) }}</span>
                    </template>
                    <template #cell:parts="{ row }">
                        <span class="text-xs text-ink-500">{{ row.parts.join(' · ') }}</span>
                    </template>
                </DataTable>
            </Card>
        </div>

        <Modal v-model:open="addingCost" width="max-w-2xl" title="Add a cost" subtitle="What the shipment cost beyond the goods.">
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField label="Type" required :error="costForm.errors.cost_type">
                    <SelectInput
                        v-model="costForm.cost_type"
                        :options="costTypes.map((t) => ({ value: t, label: titleCase(t) }))"
                    />
                </FormField>
                <FormField label="Date" required :error="costForm.errors.incurred_on">
                    <TextInput v-model="costForm.incurred_on" type="date" />
                </FormField>
                <FormField label="Description" class="sm:col-span-2" :error="costForm.errors.description">
                    <TextInput v-model="costForm.description" />
                </FormField>
                <FormField label="Vendor" hint="The C&F agent, the shipping line." :error="costForm.errors.supplier_id">
                    <SelectInput
                        v-model="costForm.supplier_id"
                        :options="vendors.map((v) => ({ value: v.id, label: v.name }))"
                    />
                </FormField>
                <FormField label="Reference" hint="Bill or B/E number." :error="costForm.errors.reference_no">
                    <TextInput v-model="costForm.reference_no" />
                </FormField>
                <FormField label="Currency" required :error="costForm.errors.currency_id">
                    <SelectInput
                        v-model="costForm.currency_id"
                        :options="currencies.map((c) => ({ value: c.id, label: c.code }))"
                    />
                </FormField>
                <FormField label="Exchange rate" :error="costForm.errors.exchange_rate">
                    <TextInput v-model="costForm.exchange_rate" type="number" step="0.00000001" numeric />
                </FormField>
                <FormField label="Amount" required :error="costForm.errors.amount">
                    <TextInput v-model="costForm.amount" type="number" step="0.0001" numeric />
                </FormField>
                <FormField label="Goes into stock cost" hint="Off for demurrage, LC charges and other period costs.">
                    <label class="flex h-9 items-center gap-2 text-sm text-ink-700">
                        <input v-model="costForm.is_allocable" type="checkbox" class="rounded border-slate-300">
                        Allocable to the goods
                    </label>
                </FormField>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="costForm.processing" @click="submitCost">Add cost</Button>
            </template>
        </Modal>

        <Modal v-model:open="linking" title="Link a goods receipt" subtitle="Only unclaimed receipts from this supplier.">
            <FormField label="Goods receipt" required :error="linkForm.errors.grn_id">
                <SelectInput
                    v-model="linkForm.grn_id"
                    :options="linkableReceipts.map((g) => ({ value: g.id, label: `${g.number} · ${date(g.received_on)}` }))"
                />
            </FormField>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="linkForm.processing" @click="link">Link</Button>
            </template>
        </Modal>

        <Modal
            v-model:open="allocating"
            title="Allocate landed cost"
            subtitle="Rewrites the landed rate on every linked receipt line and the unit cost of the lots they created."
        >
            <div class="space-y-3">
                <FormField label="Spread by" :error="allocateForm.errors.basis">
                    <SelectInput
                        v-model="allocateForm.basis"
                        :options="[
                            { value: 'value', label: 'Line value — the usual choice' },
                            { value: 'qty', label: 'Quantity — where the cost really is per unit' },
                        ]"
                    />
                </FormField>

                <p class="text-xs text-ink-500">
                    A kilo of imported ink and a kilo of carton board do not carry the same share of a duty bill,
                    so value is the default. Running this again replaces the previous allocation rather than
                    stacking on it, so a corrected bill is just a re-run.
                </p>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="allocateForm.processing" @click="allocate">Allocate</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
