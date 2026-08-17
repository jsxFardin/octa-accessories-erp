<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import { date, pcs, qty } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    packingList: { type: Object, required: true },
    cartons: { type: Array, default: () => [] },
    orderLines: { type: Array, default: () => [] },
    availableLots: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
    challans: { type: Array, default: () => [] },
});

const isDraft = props.packingList.status === 'draft';

const cartonForm = useForm({ gross_weight_kg: null, net_weight_kg: null });
const contentForms = ref({});

function contentForm(cartonId) {
    contentForms.value[cartonId] ??= useForm({
        sales_order_line_id: props.orderLines[0]?.id ?? null,
        lot_id: null,
        qty: null,
        bundles: null,
    });

    return contentForms.value[cartonId];
}

function addCarton() {
    cartonForm.post(`/packing-lists/${props.packingList.id}/cartons`, {
        preserveScroll: true,
        onSuccess: () => cartonForm.reset(),
    });
}

function addContent(cartonId) {
    contentForm(cartonId).post(`/packing-lists/${props.packingList.id}/cartons/${cartonId}/contents`, {
        preserveScroll: true,
        onSuccess: () => contentForm(cartonId).reset('lot_id', 'qty', 'bundles'),
    });
}

function transition(to) {
    router.post(`/packing-lists/${props.packingList.id}/transition`, { to }, { preserveScroll: true });
}

const challanForm = useForm({ packing_list_id: props.packingList.id, mode: 'own_fleet' });

function createChallan() {
    challanForm.post('/delivery-challans');
}
</script>

<template>
    <AppLayout>
        <Head :title="packingList.number ?? 'Packing list'" />

        <template #title>{{ packingList.number ?? '(draft packing list)' }}</template>
        <template #subtitle>
            <Link v-if="packingList.sales_order" :href="`/sales-orders/${packingList.sales_order.id}`" class="hover:underline">
                {{ packingList.sales_order.number }}
            </Link>
            · packed {{ date(packingList.packed_on) }}
        </template>

        <template #actions>
            <Badge :status="packingList.status" />
            <Button v-if="availableTransitions.includes('packed')" size="sm" variant="primary" @click="transition('packed')">
                Confirm packed
            </Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="transition('cancelled')">
                Cancel
            </Button>
            <Button
                v-if="packingList.status === 'packed' && challans.length === 0 && can('delivery_challan.create')"
                size="sm" variant="primary" :disabled="challanForm.processing" @click="createChallan"
            >
                Create challan
            </Button>
        </template>

        <div class="space-y-4">
            <Card title="Totals" rule="AC4 · computed, never typed">
                <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
                    <div><dt class="text-xs text-ink-500">Cartons</dt><dd class="font-medium tnum">{{ packingList.total_cartons }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Pieces</dt><dd class="font-medium tnum">{{ pcs(packingList.total_qty) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Gross kg</dt><dd class="font-medium tnum">{{ packingList.gross_weight_kg ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Net kg</dt><dd class="font-medium tnum">{{ packingList.net_weight_kg ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-xs text-ink-500">Claim</dt>
                        <dd class="font-medium">
                            <span v-if="packingList.cert_claim_scheme">{{ packingList.cert_claim_scheme }} {{ packingList.cert_claim_pct }}%</span>
                            <span v-else class="text-ink-400">none</span>
                        </dd>
                    </div>
                </dl>
            </Card>

            <Card title="Cartons" rule="D1" subtitle="Every content row names its lot — traceable back to a GRN in one query" :padded="false">
                <div class="divide-y divide-slate-100">
                    <div v-for="carton in cartons" :key="carton.id" class="px-4 py-3">
                        <div class="flex items-center justify-between">
                            <span class="font-medium text-ink-900">Carton {{ carton.carton_no }}</span>
                            <span class="font-mono text-[11px] text-ink-400">{{ carton.barcode }}</span>
                            <Button
                                v-if="isDraft && can('packing_list.update')" size="xs" variant="danger"
                                @click="router.delete(`/packing-lists/${packingList.id}/cartons/${carton.id}`, { preserveScroll: true })"
                            >Remove</Button>
                        </div>

                        <ul class="mt-2 space-y-1 text-sm">
                            <li v-for="content in carton.contents" :key="content.id" class="flex items-center justify-between gap-2">
                                <span>{{ content.product_code }} · lot <span class="font-mono text-xs">{{ content.lot_no }}</span></span>
                                <Badge v-if="content.lot_status !== 'available'" tone="warning" :label="content.lot_status" />
                                <span class="tnum">{{ pcs(content.qty) }}</span>
                                <Button
                                    v-if="isDraft && can('packing_list.update')" size="xs"
                                    @click="router.delete(`/packing-lists/${packingList.id}/cartons/${carton.id}/contents/${content.id}`, { preserveScroll: true })"
                                >×</Button>
                            </li>
                            <li v-if="!carton.contents.length" class="text-xs text-ink-400">Empty carton.</li>
                        </ul>

                        <form
                            v-if="isDraft && can('packing_list.update')"
                            class="mt-2 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-2"
                            @submit.prevent="addContent(carton.id)"
                        >
                            <FormField label="Order line" class="w-40">
                                <select v-model="contentForm(carton.id).sales_order_line_id" class="w-full rounded-md border-slate-300 text-xs">
                                    <option v-for="line in orderLines" :key="line.id" :value="line.id">
                                        #{{ line.line_no }} {{ line.product_code }}
                                    </option>
                                </select>
                            </FormField>
                            <FormField label="Available FG lot" class="w-56">
                                <select v-model="contentForm(carton.id).lot_id" class="w-full rounded-md border-slate-300 text-xs">
                                    <option :value="null" disabled>Pick a lot…</option>
                                    <option v-for="lot in availableLots" :key="lot.id" :value="lot.id">
                                        {{ lot.lot_no }} · {{ qty(lot.balance_qty) }} on hand<template v-if="lot.cert_scheme"> · {{ lot.cert_scheme }}</template>
                                    </option>
                                </select>
                            </FormField>
                            <FormField label="Qty" class="w-28">
                                <input v-model="contentForm(carton.id).qty" type="number" min="1" step="any" class="w-full rounded-md border-slate-300 text-xs" />
                            </FormField>
                            <Button type="submit" size="xs" variant="primary">Add</Button>
                        </form>
                    </div>

                    <div v-if="!cartons.length" class="px-4 py-6 text-center text-sm text-ink-500">
                        No cartons yet — packing starts by adding one.
                    </div>
                </div>

                <form
                    v-if="isDraft && can('packing_list.update')"
                    class="flex items-end gap-2 border-t border-slate-200 px-4 py-3"
                    @submit.prevent="addCarton"
                >
                    <FormField label="Gross kg" class="w-28">
                        <input v-model="cartonForm.gross_weight_kg" type="number" min="0" step="any" class="w-full rounded-md border-slate-300 text-xs" />
                    </FormField>
                    <FormField label="Net kg" class="w-28">
                        <input v-model="cartonForm.net_weight_kg" type="number" min="0" step="any" class="w-full rounded-md border-slate-300 text-xs" />
                    </FormField>
                    <Button type="submit" size="sm">Add carton</Button>
                </form>
            </Card>

            <Card v-if="challans.length" title="Challans" :padded="false">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="challan in challans" :key="challan.id" class="flex items-center justify-between px-4 py-2">
                        <Link :href="`/delivery-challans/${challan.id}`" class="font-medium text-brand-700">
                            {{ challan.number ?? '(draft challan)' }}
                        </Link>
                        <span class="text-xs text-ink-500">{{ date(challan.challan_date) }}</span>
                        <Badge :status="challan.status" />
                    </li>
                </ul>
            </Card>
        </div>
    </AppLayout>
</template>
