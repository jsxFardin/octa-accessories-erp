<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DocumentActions from '@/Components/Ui/DocumentActions.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, pcs, qty } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';

const props = defineProps({
    packingList: { type: Object, required: true },
    cartons: { type: Array, default: () => [] },
    orderLines: { type: Array, default: () => [] },
    availableLots: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
    challans: { type: Array, default: () => [] },
});

const isDraft = props.packingList.status === 'draft';

/*
 * `total_cartons` / `total_qty` are denormalised and only rewritten when the list transitions
 * to `packed`, so a draft with three loaded cartons read "0 cartons, 0 pieces" — the one
 * screen where the number is being built is the one screen that could not show it. The stored
 * columns stay authoritative for every other reader; here the display derives from the rows
 * it is already rendering.
 */
const totals = computed(() => {
    const contents = props.cartons.flatMap((carton) => carton.contents ?? []);
    const sum = (rows, key) => rows.reduce((total, row) => total + (Number(row[key]) || 0), 0);
    // Rounded, because these are kilograms summed in binary floating point: three cartons at
    // 9.80 printed as 29.400000000000002, on a document that goes to a customer and a customs
    // broker. Three decimals is finer than any scale on a packing bench.
    const weight = (key) => (props.cartons.some((carton) => carton[key] !== null)
        ? Math.round(sum(props.cartons, key) * 1000) / 1000
        : null);

    return {
        cartons: props.cartons.length,
        qty: sum(contents, 'qty'),
        gross: weight('gross_weight_kg'),
        net: weight('net_weight_kg'),
    };
});

/**
 * BR-44 — what the order will actually accept.
 *
 * The packer's whole question is "am I done?", and this screen could not answer it: it showed
 * a piece count with no denominator. The band is only enforced at the challan, so packing to
 * the wrong quantity was discovered one document later, after the cartons were sealed.
 *
 * Per line, the delivery band is `ordered × (1 − under)` to `ordered × (1 + over)`, less what
 * earlier challans already delivered.
 */
const band = computed(() => {
    const ordered = props.orderLines.reduce((total, line) => total + Number(line.ordered_qty || 0), 0);

    if (ordered <= 0) return null;

    const min = props.orderLines.reduce(
        (total, line) => total + Number(line.ordered_qty || 0) * (1 - Number(line.under_tolerance_pct || 0) / 100)
            - Number(line.delivered_qty || 0),
        0,
    );
    const max = props.orderLines.reduce(
        (total, line) => total + Number(line.ordered_qty || 0) * (1 + Number(line.over_tolerance_pct || 0) / 100)
            - Number(line.delivered_qty || 0),
        0,
    );

    const packed = totals.value.qty;

    return {
        ordered,
        min: Math.max(0, Math.round(min)),
        max: Math.max(0, Math.round(max)),
        packed,
        short: Math.max(0, Math.round(min - packed)),
        over: Math.max(0, Math.round(packed - max)),
        state: packed > max ? 'over' : (packed < min ? 'short' : 'within'),
    };
});

const bandTone = computed(() => ({
    within: 'text-emerald-700',
    short: 'text-amber-700',
    over: 'text-rose-700',
}[band.value?.state] ?? ''));

/**
 * A cancelled challan is not a challan. Counting it kept the button hidden for good, so one
 * cancelled draft stranded a packed list with no way to dispatch it — while the server was
 * perfectly willing to raise another (`DeliveryChallanController::store` excludes cancelled).
 * The screen was refusing something nothing else refused.
 */
const liveChallans = computed(() => props.challans.filter((challan) => challan.status !== 'cancelled'));

const cartonForm = useForm({ gross_weight_kg: null, net_weight_kg: null, count: 1 });

/** Packers work through one lot at a time; making them re-pick it every carton is friction. */
const lastLotId = ref(null);

/** Weights, editable in place — a mis-keyed scale reading should not cost a carton. */
const weightForms = ref({});

function weightForm(carton) {
    weightForms.value[carton.id] ??= useForm({
        gross_weight_kg: carton.gross_weight_kg,
        net_weight_kg: carton.net_weight_kg,
    });

    return weightForms.value[carton.id];
}

function saveWeights(carton) {
    weightForm(carton).put(`/packing-lists/${props.packingList.id}/cartons/${carton.id}`, {
        preserveScroll: true,
    });
}

function cartonQty(carton) {
    return (carton.contents ?? []).reduce((total, row) => total + Number(row.qty || 0), 0);
}

/**
 * One pack bar for the whole list, rather than a copy of it inside every carton.
 *
 * The per-carton form repeated the same four fields on every card — three cartons filled some
 * 40% of the page with empty inputs, and a real list is fifty-three of them, because the BOM
 * packs one carton per 1,000 labels. Packing is one action repeated, not fifty-three forms.
 */
const packForm = useForm({
    sales_order_line_id: props.orderLines[0]?.id ?? null,
    lot_id: props.availableLots.length === 1 ? props.availableLots[0].id : null,
    qty: null,
    bundles: null,
});

/** Which carton the bar is pointed at. Not on the form — it is the URL, not a field. */
const packInto = ref(null);

const emptyCartons = computed(() => props.cartons.filter((carton) => !(carton.contents ?? []).length));

const cartonOptions = computed(() => props.cartons.map((carton) => {
    const packed = cartonQty(carton);

    return {
        value: carton.id,
        label: `Carton ${carton.carton_no}`,
        hint: packed > 0 ? `${pcs(packed)} packed` : 'empty',
    };
}));

/** The next carton with nothing in it — where the packer is almost certainly going next. */
function nextEmptyCarton(afterId = null) {
    const empties = emptyCartons.value.filter((carton) => carton.id !== afterId);

    return empties[0]?.id ?? null;
}

/**
 * Packing is repetitive — one carton per 1,000 labels means a 52,500-piece list is 53 cartons,
 * all the same. Adding them one at a time was 53 round trips.
 */
function addCarton() {
    const count = Math.max(1, Math.min(200, Number(cartonForm.count) || 1));
    let remaining = count;

    const post = () => cartonForm.post(`/packing-lists/${props.packingList.id}/cartons`, {
        preserveScroll: true,
        onSuccess: () => {
            remaining -= 1;
            if (remaining > 0) post();
            else cartonForm.reset();
        },
    });

    post();
}

/**
 * Add, then move to the next empty carton with the lot and quantity still in the fields.
 *
 * Fifty-three identical cartons is the ordinary case, so the fast path has to be: set the
 * quantity once, then press Add once per carton. Re-picking the lot and re-typing the figure
 * every time is the friction this removes.
 */
function pack() {
    const cartonId = packInto.value;

    if (!cartonId) return;

    packForm.post(`/packing-lists/${props.packingList.id}/cartons/${cartonId}/contents`, {
        preserveScroll: true,
        onSuccess: () => {
            lastLotId.value = packForm.lot_id;
            packInto.value = nextEmptyCarton(cartonId) ?? cartonId;
        },
    });
}

/*
 * Keep the bar pointed somewhere real as cartons come and go: the first empty one, or the last
 * carton when they are all full.
 */
watch(
    () => props.cartons.map((carton) => `${carton.id}:${(carton.contents ?? []).length}`).join(','),
    () => {
        const stillThere = props.cartons.some((carton) => carton.id === packInto.value);

        if (!stillThere) {
            packInto.value = nextEmptyCarton() ?? props.cartons.at(-1)?.id ?? null;
        }
    },
    { immediate: true },
);

/*
 * Lot lists run long once a week's output is in finished goods, and a native <select> makes
 * the packer scroll for a number they can spell. Both pickers carry their second line — what
 * is on hand, which scheme it carries — where the packer needs it: in the list, not after.
 */
const lineOptions = computed(() => props.orderLines.map((line) => ({
    value: line.id,
    label: `#${line.line_no} ${line.product_code}`,
    hint: `${pcs(line.ordered_qty)} ordered · ${pcs(line.delivered_qty)} delivered`,
})));

const lotOptions = computed(() => props.availableLots.map((lot) => ({
    value: lot.id,
    label: lot.lot_no,
    hint: `${qty(lot.balance_qty)} on hand${lot.cert_scheme ? ` · ${lot.cert_scheme}` : ''}`,
})));

const confirmTransition = useTransitionConfirm();

async function transition(to) {
    if (!(await confirmTransition(to, props.packingList.number))) return;

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
            <Link v-if="packingList.sales_order" :href="`/sales-orders/${packingList.sales_order.id}`" class="doc-link">
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
                v-if="packingList.status === 'packed' && liveChallans.length === 0 && can('delivery_challan.create')"
                size="sm" variant="primary" :loading="challanForm.processing" :disabled="challanForm.processing" @click="createChallan"
            >
                Create challan
            </Button>
            <DocumentActions document="packing-lists" :id="packingList.id" :status="packingList.status" />
        </template>

        <div class="space-y-4">
            <Card title="Totals" rule="AC4 · computed, never typed">
                <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
                    <div><dt class="text-xs text-ink-500">Cartons</dt><dd class="font-medium tnum">{{ totals.cartons }}</dd></div>
                    <div>
                        <dt class="text-xs text-ink-500">Pieces</dt>
                        <dd class="font-medium tnum">
                            {{ pcs(totals.qty) }}<span v-if="band" class="text-ink-400"> / {{ pcs(band.ordered) }}</span>
                        </dd>
                        <!--
                            The answer to "am I done?", which is the only question this screen
                            exists to settle. Without it the packer finds out at the challan,
                            where BR-44 refuses the issue — one document too late.
                        -->
                        <dd v-if="band" class="text-[11px]" :class="bandTone">
                            <span v-if="band.state === 'within'">within the {{ pcs(band.min) }}–{{ pcs(band.max) }} band</span>
                            <span v-else-if="band.state === 'short'">{{ pcs(band.short) }} short of {{ pcs(band.min) }}</span>
                            <span v-else>{{ pcs(band.over) }} over the {{ pcs(band.max) }} ceiling</span>
                        </dd>
                    </div>
                    <div><dt class="text-xs text-ink-500">Gross kg</dt><dd class="font-medium tnum">{{ totals.gross ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Net kg</dt><dd class="font-medium tnum">{{ totals.net ?? '—' }}</dd></div>
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
                <!--
                    One bar for the whole list. It keeps the lot and the quantity between adds
                    and steps to the next empty carton on its own, so packing fifty-three
                    identical cartons is fifty-three presses of one button rather than
                    fifty-three forms filled in from scratch.
                -->
                <form
                    v-if="isDraft && can('packing_list.update') && cartons.length"
                    class="flex flex-wrap items-end gap-2 border-b border-slate-200 bg-slate-50/60 px-4 py-3"
                    @submit.prevent="pack"
                >
                    <FormField label="Into" class="w-40">
                        <SelectInput v-model="packInto" :options="cartonOptions" hint-key="hint" :placeholder="null" />
                    </FormField>
                    <FormField label="Order line" class="w-44">
                        <SelectInput v-model="packForm.sales_order_line_id" :options="lineOptions" hint-key="hint" :placeholder="null" />
                    </FormField>
                    <FormField label="Available FG lot" class="w-56" :error="packForm.errors.lot_id">
                        <SelectInput v-model="packForm.lot_id" :options="lotOptions" hint-key="hint" placeholder="Pick a lot…" />
                    </FormField>
                    <FormField label="Qty" class="w-28" :error="packForm.errors.qty">
                        <TextInput v-model="packForm.qty" type="number" min="1" step="any" numeric placeholder="0" />
                    </FormField>
                    <Button
                        type="submit"
                        size="sm"
                        variant="primary"
                        :loading="packForm.processing"
                        :disabled="!packInto || !packForm.lot_id || !packForm.qty"
                    >
                        Pack
                    </Button>
                    <span v-if="emptyCartons.length" class="pb-1 text-xs text-ink-500">
                        {{ emptyCartons.length }} carton{{ emptyCartons.length === 1 ? '' : 's' }} still empty
                    </span>
                </form>

                <div class="divide-y divide-slate-100">
                    <div v-for="carton in cartons" :key="carton.id" class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="font-medium text-ink-900">Carton {{ carton.carton_no }}</span>
                            <span class="font-mono text-[11px] text-ink-400">{{ carton.barcode }}</span>

                            <!-- What is in this one. Adding the rows up by eye was the alternative. -->
                            <span class="text-xs text-ink-500 tnum">{{ pcs(cartonQty(carton)) }} pcs</span>

                            <span v-if="!isDraft && (carton.gross_weight_kg || carton.net_weight_kg)" class="text-xs text-ink-500 tnum">
                                {{ carton.gross_weight_kg ?? '—' }} / {{ carton.net_weight_kg ?? '—' }} kg
                            </span>

                            <!-- Weights, correctable in place. -->
                            <span v-if="isDraft && can('packing_list.update')" class="flex items-center gap-1">
                                <TextInput
                                    v-model="weightForm(carton).gross_weight_kg"
                                    cell type="number" min="0" step="any" numeric class="w-20"
                                    placeholder="gross" @blur="saveWeights(carton)"
                                />
                                <TextInput
                                    v-model="weightForm(carton).net_weight_kg"
                                    cell type="number" min="0" step="any" numeric class="w-20"
                                    placeholder="net" @blur="saveWeights(carton)"
                                />
                                <span class="text-[11px] text-ink-400">kg</span>
                            </span>

                            <!--
                                Destructive, and no longer the loudest thing on the card. A big
                                red button next to a quiet `Add` invites the wrong click; the
                                content rows already use a quiet × for the same act.
                            -->
                            <Button
                                v-if="isDraft && can('packing_list.update')" size="xs"
                                class="ml-auto text-ink-400 hover:text-rose-600"
                                :aria-label="`Remove carton ${carton.carton_no}`"
                                @click="router.delete(`/packing-lists/${packingList.id}/cartons/${carton.id}`, { preserveScroll: true })"
                            >×</Button>
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
                        <TextInput v-model="cartonForm.gross_weight_kg" type="number" min="0" step="any" numeric placeholder="0.00" />
                    </FormField>
                    <FormField label="Net kg" class="w-28">
                        <TextInput v-model="cartonForm.net_weight_kg" type="number" min="0" step="any" numeric placeholder="0.00" />
                    </FormField>
                    <FormField label="How many" class="w-24" hint="Same weights">
                        <TextInput v-model="cartonForm.count" type="number" min="1" max="200" numeric placeholder="1" />
                    </FormField>
                    <Button type="submit" size="sm" :loading="cartonForm.processing">
                        Add {{ Number(cartonForm.count) > 1 ? `${cartonForm.count} cartons` : 'carton' }}
                    </Button>
                </form>
            </Card>

            <Card v-if="challans.length" title="Challans" :padded="false"
                  :subtitle="liveChallans.length === 0 ? 'All cancelled — this list can raise a new one.' : null">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="challan in challans" :key="challan.id" class="flex items-center justify-between px-4 py-2">
                        <Link :href="`/delivery-challans/${challan.id}`" class="doc-link-quiet">
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
