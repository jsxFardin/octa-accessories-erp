<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useBookedRate } from '@/composables/useBookedRate';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import ContextNotice from '@/Components/Ui/ContextNotice.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import RuleHint from '@/Components/Ui/RuleHint.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import LineItemsTable from '@/Components/Ui/LineItemsTable.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import { addCalendarDays, baseCurrency, date, decimals, isoDate, money, number, pcs, qty, rate, titleCase, todayIso, typed, unitCost } from '@/plugins/formatting';

const props = defineProps({
    quotation: { type: Object, default: null },
    inquiryId: { type: Number, default: null },
    /** The inquiry behind `Quote it`, already resolved server-side. */
    inquiryPrefill: { type: Object, default: null },
    /** F-09 — set when `?inquiry=` named something that could not be opened. */
    contextNotice: { type: Object, default: null },
    customers: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    defaultMarginPct: { type: [Number, String], default: 20 },
    marginFloorPct: { type: [Number, String], default: 12 },
});

const isEdit = computed(() => Boolean(props.quotation));

function blankLine() {
    return {
        // F-05 — set only when this line answers an inquiry line, so a later reader can see
        // what was asked for beside what was quoted.
        inquiry_line_id: null,
        product_id: '',
        product_spec_id: '',
        description: '',
        qty: '',
        margin_pct: Number(props.defaultMarginPct),
        rate_per_m: '',
        tooling_charge: 0,
        lead_time_days: '',
    };
}

/**
 * An inquiry line becomes a quotation line: the product and quantity carry across, the rate
 * does not — it is computed from the cost sheet once the line is priced (BR-20). A line the
 * customer described without a product keeps its wording and waits for one to be chosen.
 */
function lineFromInquiry(line) {
    return {
        ...blankLine(),
        // The provenance of this line. The quantity below is a *default*, not a constraint:
        // quoting a different quantity from the one inquired is ordinary (a sample volume
        // quoted at the minimum order quantity, say), and the pairing is what makes the
        // difference visible instead of unexplainable.
        inquiry_line_id: line.id ?? null,
        product_id: line.product_id ?? '',
        description: line.description ?? '',
        qty: typed(line.qty),
    };
}

const prefill = props.quotation ? null : props.inquiryPrefill;

const form = useForm({
    inquiry_id: props.quotation?.inquiry_id ?? props.inquiryId ?? '',
    customer_id: props.quotation?.customer_id ?? prefill?.customer_id ?? '',
    quotation_date: isoDate(props.quotation?.quotation_date) || todayIso(),
    // Thirty days, the same default a duplicated quotation gets; a new one used to start with none.
    valid_until: isoDate(props.quotation?.valid_until) || (props.quotation ? '' : addCalendarDays(todayIso(), 30)),
    currency_id:
        props.quotation?.currency_id
        ?? prefill?.currency_id
        ?? props.currencies.find((c) => c.is_base)?.id
        ?? '',
    exchange_rate: props.quotation?.exchange_rate ?? 1,
    terms: props.quotation?.terms ?? '',
    // Stored decimals load as typed numbers: "8000.0000" in a quantity cell is the database talking.
    lines: props.quotation?.lines?.length
        ? props.quotation.lines.map((line) => ({
            ...line,
            qty: typed(line.qty),
            margin_pct: typed(line.margin_pct),
            tooling_charge: typed(line.tooling_charge) || 0,
            lead_time_days: typed(line.lead_time_days),
        }))
        : prefill?.lines?.length
            ? prefill.lines.map(lineFromInquiry)
            : [blankLine()],
});

/* An offer usually stands for one, two or three months; counted from the quotation date. */
const VALIDITY_PRESETS = [30, 60, 90];

function presetValidity(days) {
    form.valid_until = addCalendarDays(form.quotation_date || todayIso(), days);
}

/** The rate follows the currency: filled from the rate on file, and no field at all for base. */
const { isBase: baseCurrencyDocument, rateHint } = useBookedRate(form, () => props.currencies, { existing: Boolean(props.quotation) });

/** Lines the inquiry described in words rather than naming a product — still to resolve. */
const unresolvedFromInquiry = computed(
    () => (prefill?.lines ?? []).filter((line) => !line.product_id).length,
);

/** Products belong to exactly one customer, so the picker narrows with the header. */
const availableProducts = computed(() =>
    form.customer_id
        ? props.products.filter((product) => product.customer_id === Number(form.customer_id))
        : props.products,
);

// --- Live cost sheet ---------------------------------------------------------------------
// The rate is not typed; it is computed from the spec, the routing and the margin, and the
// merchandiser sees which rule produced each number (08-architecture §4).
const sheets = ref({});
const pending = ref({});

/**
 * Each line is priced on its own, a moment after the typing stops, and only the newest answer
 * for a line is believed.
 *
 * Every change to any line used to re-price every line at once, on every keystroke, with
 * nothing to stop a slow earlier answer landing after a newer one. On a ten-line quotation
 * typing a four-digit quantity sent forty requests, the rates flickered, and on a poor
 * connection the rate that finally stuck could belong to a quantity typed two digits ago.
 */
const PRICE_DELAY_MS = 400;
const timers = new Map();
/** The number of the latest request sent for each line; an answer to an older one is dropped. */
const latest = new Map();

function schedulePrice(line) {
    clearTimeout(timers.get(line));
    timers.set(line, setTimeout(() => priceLine(line), PRICE_DELAY_MS));
}

async function priceLine(line) {
    const index = form.lines.indexOf(line);

    if (index === -1) return;

    if (!line.product_id || !line.qty) {
        line.rate_per_m = '';

        // Removed, not set to undefined: the breakdown cards below iterate this map.
        const { [index]: dropped, ...rest } = sheets.value;

        sheets.value = rest;

        return;
    }

    const ticket = (latest.get(line) ?? 0) + 1;

    latest.set(line, ticket);
    pending.value = { ...pending.value, [index]: true };

    /** The line may have moved up the table, or been removed, while the request was out. */
    const settle = (sheet, rate) => {
        if (latest.get(line) !== ticket) return;

        const now = form.lines.indexOf(line);

        if (now === -1) return;

        line.rate_per_m = rate;
        sheets.value = { ...sheets.value, [now]: sheet };
        pending.value = { ...pending.value, [now]: false };
    };

    try {
        const response = await fetch('/cost-sheets/calculate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                product_id: Number(line.product_id),
                product_spec_id: line.product_spec_id || null,
                qty: Number(line.qty),
                margin_pct: Number(line.margin_pct),
                exchange_rate: Number(form.exchange_rate) || 1,
                currency: props.currencies.find((c) => c.id === Number(form.currency_id))?.code,
            }),
        });

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));

            // A rate left over from before the failure would still save, against a spec that
            // no longer prices.
            settle({ error: Object.values(payload.errors ?? {}).flat()[0] ?? 'Could not price this line.' }, '');

            return;
        }

        const payload = await response.json();

        // The computed rate is the answer; typing over it is what BR-20 exists to prevent.
        settle(payload, payload.sheet.rate_per_m_in_currency);
    } catch {
        settle({ error: 'Could not reach the cost sheet. Check the connection and change the line to retry.' }, '');
    }
}

/** What feeds each line's rate. A line is re-priced when its own inputs change, not its neighbours'. */
const signatures = new WeakMap();

watch(
    () => form.lines.map((line) => `${line.product_id}|${line.qty}|${line.margin_pct}`),
    (now) => {
        form.lines.forEach((line, index) => {
            if (signatures.get(line) !== now[index]) {
                signatures.set(line, now[index]);
                schedulePrice(line);
            }
        });
    },
);

/**
 * A prefilled line arrives with a product and a quantity already on it, so the watcher above
 * — which only fires on a *change* — never ran and the rate stayed empty. Price what came in
 * from the inquiry, or with an existing draft, as soon as the form mounts.
 */
onMounted(() => {
    // Arriving from "Create a quotation" on a product: that product, for its customer, on line 1.
    const wanted = Number(new URLSearchParams(window.location.search).get('product'));
    const product = props.quotation || prefill ? null : props.products.find((row) => row.id === wanted);

    if (product) {
        form.customer_id = product.customer_id;
        form.lines[0].product_id = product.id;
        form.lines[0].description = product.name;
    }

    form.lines.forEach((line) => {
        signatures.set(line, `${line.product_id}|${line.qty}|${line.margin_pct}`);

        if (line.product_id && line.qty) priceLine(line);
    });
});

// The currency or its rate changed: every rate on the page is in the old one.
watch(() => [form.exchange_rate, form.currency_id], () => form.lines.forEach((line) => schedulePrice(line)));

function addLine() {
    form.lines = [...form.lines, blankLine()];
}

/** The sheets are kept by row position, so removing a row moves the ones below it up. */
function removeLine(index) {
    const shift = (map) => Object.fromEntries(
        Object.entries(map)
            .filter(([key]) => Number(key) !== index)
            .map(([key, value]) => [Number(key) > index ? Number(key) - 1 : Number(key), value]),
    );

    sheets.value = shift(sheets.value);
    pending.value = shift(pending.value);
    form.lines = form.lines.filter((_, i) => i !== index);
}

function lineTotal(line) {
    return (Number(line.qty) || 0) / 1000 * (Number(line.rate_per_m) || 0) + (Number(line.tooling_charge) || 0);
}

const subtotal = computed(() => form.lines.reduce((sum, line) => sum + lineTotal(line), 0));

const currencyCode = computed(
    () => props.currencies.find((c) => c.id === Number(form.currency_id))?.code ?? '',
);

const belowFloor = computed(() =>
    form.lines.some((line) => Number(line.margin_pct) < Number(props.marginFloorPct)),
);

const filledLines = computed(() => form.lines.filter((line) => line.product_id || line.qty).length);

/**
 * A line the inquiry described in words has no product, so nothing can price it. The server
 * rejects that with "the rate per 1000 field is required", which names a field that is not on
 * the form. Say what is actually missing, and keep the save button from being the way anyone
 * finds out.
 */
/**
 * "Still being priced" is only true while the request is out. Once it has come back, the line
 * either failed — and the cost sheet said why — or priced at nothing, which means the product
 * has no cost on it to mark up.
 */
function unpricedReason(index) {
    if (pending.value[index]) {
        return 'is still being priced';
    }

    if (sheets.value[index]?.error) {
        return `could not be priced — ${sheets.value[index].error}`;
    }

    if (sheets.value[index]?.sheet) {
        return 'priced at zero — the product has no material rate on its BOM and no costed routing';
    }

    return 'is still being priced';
}

const unpriced = computed(() =>
    form.lines
        .map((line, index) => ({ line, index }))
        .filter(({ line }) => !isBlank(line) && (!line.product_id || !line.qty || !line.rate_per_m))
        .map(({ line, index }) => ({
            no: index + 1,
            productId: line.product_id || null,
            reason: !line.product_id
                ? 'needs a product before it can be priced'
                : !line.qty
                    ? 'needs a quantity'
                    : unpricedReason(index),
        })),
);

const totalQty = computed(() => form.lines.reduce((sum, line) => sum + (Number(line.qty) || 0), 0));

const selectedCustomer = computed(
    () => props.customers.find((customer) => String(customer.id) === String(form.customer_id)) ?? null,
);

/** A row nobody typed anything into — the blank one a new form starts with, or a stray "Add line". */
function isBlank(line) {
    return !line.product_id && !String(line.description ?? '').trim() && !line.qty;
}

/** Why the draft cannot be saved at all. Unpriced lines are not on this list: a draft may have them. */
const blockedBy = computed(() => {
    if (!form.customer_id) return 'Choose the customer this quotation is for.';

    const lines = form.lines.filter((line) => !isBlank(line));

    if (lines.length === 0) return 'Add at least one line.';

    const noQuantity = form.lines.map((line, index) => ({ line, index }))
        .filter(({ line }) => !isBlank(line) && !(Number(line.qty) > 0));

    if (noQuantity.length) return `Line ${noQuantity.map(({ index }) => index + 1).join(', ')} needs a quantity.`;

    return null;
});

function submit() {
    form
        .transform((data) => ({
            ...data,
            // Blank rows are dropped rather than failing the save, and a line with a product
            // but no wording of its own is described by the product's name.
            lines: data.lines.filter((line) => !isBlank(line)).map((line) => ({
                ...line,
                description: String(line.description ?? '').trim()
                    || props.products.find((product) => product.id === Number(line.product_id))?.name
                    || '',
                rate_per_m: line.rate_per_m === '' ? null : line.rate_per_m,
                product_id: line.product_id || null,
            })),
        }));

    isEdit.value
        ? form.put(`/quotations/${props.quotation.id}`)
        : form.post('/quotations');
}

/**
 * Products belong to one customer. Changing the customer used to narrow the picker and leave
 * the previous customer's products sitting on the lines, where they could be saved.
 */
const droppedProducts = ref(0);

watch(() => form.customer_id, (customer) => {
    droppedProducts.value = 0;

    if (!customer) return;

    form.lines.forEach((line) => {
        const product = props.products.find((row) => row.id === Number(line.product_id));

        if (product && product.customer_id !== Number(customer)) {
            line.product_id = '';
            line.product_spec_id = '';
            line.rate_per_m = '';
            droppedProducts.value += 1;
        }
    });
});

// Description is the widest column: it is what the customer reads on the document, and every
// other column is a pick-list or a number. The widths on the rest are ceilings, not shares.
const columns = [
    { key: 'product_id', label: 'Product', width: '13rem', errorKeys: ['product_id', 'product_spec_id'] },
    { key: 'description', label: 'Description' },
    { key: 'qty', label: 'Quantity', width: '7rem', align: 'right' },
    { key: 'margin_pct', label: 'Margin %', width: '6rem', align: 'right' },
    { key: 'rate_per_m', label: 'Rate per 1,000 pcs', width: '8rem', align: 'right' },
    // In the line's state and in its total all along, with no box to type either into.
    { key: 'tooling_charge', label: 'Tooling charge', width: '7rem', align: 'right' },
    { key: 'lead_time_days', label: 'Lead days', width: '5.5rem', align: 'right' },
    { key: 'line_total', label: 'Line value', width: '8rem', align: 'right' },
];
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? 'Edit quotation' : 'New quotation'" />

        <template #title>
            {{ isEdit ? `Quotation ${quotation.number ?? '(unnumbered)'}` : 'New quotation' }}
        </template>
        <template #subtitle>Each line is priced by the cost sheet, not typed by hand</template>

        <FormLayout @submit="submit">

            <!--
                Where this quotation came from, stated on the form rather than left implicit in
                a query string. The link back matters: the merchandiser is about to price what
                the inquiry describes and will want to re-read it.
            -->
            <!-- F-09 — the handoff that did not arrive, said out loud. -->
            <ContextNotice :notice="contextNotice" />

            <div
                v-if="prefill"
                class="rounded-lg border border-brand-200 bg-brand-50 px-3 py-2.5 text-sm text-brand-900"
            >
                <p>
                    Quoting
                    <Link :href="`/inquiries/${prefill.id}`" class="font-medium underline">
                        inquiry {{ prefill.number ?? `#${prefill.id}` }}</Link><span v-if="prefill.customer">
                        for {{ prefill.customer.name }}</span>.
                    <span v-if="prefill.lines.length">
                        {{ prefill.lines.length }} {{ prefill.lines.length === 1 ? 'line has' : 'lines have' }} been
                        carried across — rates are computed here, not copied.
                    </span>
                </p>
                <p v-if="unresolvedFromInquiry" class="mt-1 text-xs">
                    {{ unresolvedFromInquiry }} of them
                    {{ unresolvedFromInquiry === 1 ? 'was described' : 'were described' }}
                    without a product. Choose one on each before it can be priced.
                </p>
            </div>

            <Card title="Customer and dates">
                <!-- Three across until 1536 px: five across at 1280 cut the date to "05 Oct 202". -->
                <!-- Four across until 1536 px: five at 1280 cut the dates to "05 Oct 202". -->
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-6">
                    <!-- Two columns: a code and a name do not fit in one, and the name is what is read. -->
                    <FormField label="Customer" class="sm:col-span-2" :error="form.errors.customer_id" required>
                        <SelectInput
                            v-model="form.customer_id"
                            placeholder="— select —"
                            :options="customers"
                            value-key="id"
                            label-key="name"
                        />
                    </FormField>

                    <FormField label="Quotation date" :error="form.errors.quotation_date" required>
                        <DateInput v-model="form.quotation_date" />
                    </FormField>

                    <FormField label="Valid until" :error="form.errors.valid_until">
                        <DateInput v-model="form.valid_until" :min="form.quotation_date" />
                        <!-- One quiet row, not a hint and three buttons: the field already says what the date is. -->
                        <div class="mt-1 flex items-center gap-0.5 text-xs text-ink-500">
                            <button v-for="days in VALIDITY_PRESETS" :key="days" type="button" class="min-h-5 rounded px-1.5 text-brand-700 transition hover:bg-brand-50 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none" :title="`${days} days from the quotation date`" @click="presetValidity(days)">+{{ days }}d</button>
                        </div>
                    </FormField>

                    <FormField label="Currency" :error="form.errors.currency_id" required>
                        <SelectInput
                            v-model="form.currency_id"
                            :placeholder="null"
                            :options="currencies"
                            value-key="id"
                            label-key="code"
                        />
                    </FormField>

                    <FormField
                        v-if="!baseCurrencyDocument"
                        label="Exchange rate"
                        rule="BR-22"
                        :hint="rateHint ?? 'Fixed when the quotation is sent.'"
                        :error="form.errors.exchange_rate"
                        required
                    >
                        <TextInput v-model="form.exchange_rate" type="number" step="0.000001" numeric />
                    </FormField>
                </div>
            </Card>

            <Card title="Lines" rule="BR-20" :padded="false">
                <div class="p-3">
                    <LineItemsTable
                        :columns="columns"
                        :lines="form.lines"
                        :errors="form.errors"
                        add-label="Add line"
                        empty="No lines yet"
                    empty-hint="Add a product and a quantity. The price is worked out from the product's cost sheet."
                        @add="addLine"
                        @remove="removeLine"
                    >
                        <template #cell:product_id="{ line, index }">
                            <SelectInput
                                v-model="line.product_id"
                                placeholder="— product —"
                                :options="availableProducts"
                                value-key="id"
                                label-key="code"
                                hint-key="name"
                            />
                            <p v-if="line.product_id" class="mt-1 truncate text-xs text-ink-500">
                                {{ availableProducts.find((p) => p.id === Number(line.product_id))?.name }}
                            </p>
                            <p v-if="sheets[index]?.error" class="mt-1 text-xs text-rose-700">
                                {{ sheets[index].error }}
                                <a
                                    :href="`/products/${line.product_id}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="font-medium underline"
                                >Open the product in a new tab</a>
                                to fix it — this quotation stays here.
                            </p>
                        </template>

                        <template #cell:description="{ line }">
                            <TextInput cell v-model="line.description" class="min-w-56" placeholder="As it should read on the quotation" />
                        </template>

                        <template #cell:qty="{ line }">
                            <TextInput cell v-model="line.qty" type="number" numeric min="1" placeholder="0" class="min-w-24" />
                        </template>

                        <template #cell:margin_pct="{ line }">
                            <TextInput cell v-model="line.margin_pct" type="number" step="0.01" numeric placeholder="0" class="min-w-16" />
                            <p
                                v-if="Number(line.margin_pct) < Number(marginFloorPct)"
                                class="mt-1 text-xs text-amber-700"
                            >
                                below {{ marginFloorPct }}% floor
                            </p>
                        </template>

                        <template #cell:rate_per_m="{ line, index }">
                            <div class="text-right">
                                <span v-if="pending[index]" class="text-xs text-ink-500">pricing…</span>
                                <span v-else class="text-sm font-semibold tnum text-ink-900">
                                    {{ line.rate_per_m ? rate(line.rate_per_m, currencyCode) : '—' }}
                                </span>
                                <p class="text-xs text-ink-500">computed</p>
                            </div>
                        </template>

                        <template #cell:tooling_charge="{ line }">
                            <TextInput cell v-model="line.tooling_charge" type="number" step="0.01" min="0" numeric placeholder="0.00" class="min-w-20" />
                        </template>

                        <template #cell:lead_time_days="{ line }">
                            <TextInput cell v-model="line.lead_time_days" type="number" step="1" min="0" numeric placeholder="days" class="min-w-16" />
                        </template>

                        <template #cell:line_total="{ line }">
                            <span class="text-sm tnum text-ink-800">{{ money(lineTotal(line), currencyCode) }}</span>
                        </template>

                        <template #footer>
                            <tr>
                                <td colspan="7" class="px-3 py-2 text-right text-xs text-ink-700">Subtotal</td>
                                <td class="px-2 py-2 text-right text-sm font-semibold tnum text-ink-900">
                                    {{ money(subtotal, currencyCode) }}
                                </td>
                                <td />
                            </tr>
                        </template>
                    </LineItemsTable>

                    <p v-if="form.errors.lines" class="mt-2 text-xs text-rose-600">{{ form.errors.lines }}</p>

<p v-if="droppedProducts" role="status" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        {{ droppedProducts }} {{ droppedProducts === 1 ? 'line had a product' : 'lines had products' }}
                        belonging to the previous customer. {{ droppedProducts === 1 ? 'It has' : 'They have' }} been
                        cleared — choose again from this customer's products.
                    </p>

                    <div
                        v-if="unpriced.length"
                        class="mt-2 rounded-md bg-slate-50 px-3 py-2 text-xs text-ink-700"
                        role="status"
                    >
                        <p class="font-medium text-ink-900">
                            This can be saved as a draft, but not sent until every line has a price:
                        </p>
                        <ul class="mt-1 list-disc pl-4">
                            <li v-for="item in unpriced" :key="item.no">
                                Line {{ item.no }} {{ item.reason }}.
                                <a
                                    v-if="item.productId"
                                    :href="`/products/${item.productId}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="font-medium text-brand-700 underline"
                                >Open the product</a>
                                <a
                                    v-else
                                    href="/products/create"
                                    target="_blank"
                                    rel="noopener"
                                    class="font-medium text-brand-700 underline"
                                >Set up a new product</a>
                            </li>
                        </ul>
                    </div>

                    <p v-if="belowFloor" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        A line is priced below the {{ marginFloorPct }}% margin floor. Sending this
                        quotation needs someone who is allowed to approve a margin below the floor.
                    </p>
                </div>
            </Card>

            <!-- The cost breakdown behind whichever line is priced, with its rule references. -->
            <Card
                v-for="(sheet, index) in sheets"
                :key="`sheet-${index}`"
                v-show="sheet?.sheet"
                :title="`Line ${Number(index) + 1} — cost breakdown`"
                rule="BR-14 … BR-22"
                :padded="false"
            >
                <div v-if="sheet.sheet" class="grid gap-0 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <table class="min-w-full text-xs">
                            <!-- BR-22 — costs are computed in the factory's currency whatever
                                 the quotation is written in, so the columns say which. -->
                            <thead class="bg-slate-50 text-ink-700">
                                <tr>
                                    <th class="px-3 py-1.5 text-left">Cost type</th>
                                    <th class="px-3 py-1.5 text-right">Qty</th>
                                    <th class="px-3 py-1.5 text-right whitespace-nowrap">Rate ({{ baseCurrency() }})</th>
                                    <th class="px-3 py-1.5 text-right whitespace-nowrap">Amount ({{ baseCurrency() }})</th>
                                    <!-- Named for a screen reader; a hidden span here sat outside the scrolling table and widened the page. -->
                                    <th class="w-10 px-3 py-1.5" aria-label="How it is worked out" />
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="cl in sheet.sheet.lines" :key="cl.seq">
                                    <td class="px-3 py-1.5 font-medium text-ink-800">{{ titleCase(cl.cost_type) }}</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ qty(cl.qty) }}</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ number(cl.rate, decimals(), decimals()) }}</td>
                                    <td class="px-3 py-1.5 text-right font-medium tnum">{{ money(cl.amount, false) }}</td>
                                    <td class="px-3 py-1.5">
<!-- How the figure is worked out, in words, behind the marker. The rule's code used
                                             to be printed here as a badge, which reads as an error code. -->
                                        <RuleHint v-if="cl.formula_ref" :rule="cl.formula_ref" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-slate-200 p-3 text-sm lg:border-t-0 lg:border-l">
                        <p class="mb-2 text-xs font-medium tracking-wide text-ink-500 uppercase">
                            Costs in {{ baseCurrency() }}
                        </p>

                        <dl class="space-y-1.5">
                            <div class="flex justify-between">
                                <dt class="text-ink-500">Gross metres</dt>
                                <dd class="tnum">{{ qty(sheet.sheet.lines.find((l) => l.cost_type === 'material_ribbon')?.qty ?? 0) }} m</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-ink-500">Total cost</dt>
                                <dd class="tnum font-medium">{{ money(sheet.sheet.total_cost, false) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-ink-500">Unit cost / piece</dt>
                                <dd class="tnum">{{ unitCost(sheet.sheet.unit_cost, false) }}</dd>
                            </div>
                            <!-- The one figure on this panel in the customer's currency. -->
                            <div class="flex justify-between rounded bg-brand-50 px-2 py-1">
                                <dt class="font-semibold text-brand-900">Quoted rate per 1,000 pcs</dt>
                                <dd class="tnum font-semibold text-brand-900">
                                    {{ rate(sheet.sheet.rate_per_m_in_currency, currencyCode) }}
                                </dd>
                            </div>
                        </dl>

                        <p class="mt-2 text-xs text-ink-500">
                            Margin is applied on price — unit cost × 1000 ÷ (1 − margin) — not on cost.
                        </p>

                        <p
                            v-for="warning in sheet.warnings"
                            :key="warning"
                            class="mt-2 rounded bg-amber-50 px-2 py-1 text-xs text-amber-900"
                        >
                            {{ warning }}
                        </p>
                    </div>
                </div>
            </Card>

            <template #rail>
                <Card title="Quotation">
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Customer</dt>
                            <dd class="min-w-0 truncate text-right" :class="selectedCustomer ? 'text-ink-900' : 'text-ink-400'">
                                {{ selectedCustomer?.name ?? 'Not chosen' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Valid until</dt>
                            <dd class="text-right" :class="form.valid_until ? 'text-ink-900' : 'text-ink-400'">
                                {{ form.valid_until ? date(form.valid_until) : 'Open' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-2.5">
                            <dt class="text-xs text-ink-500">Lines</dt>
                            <dd class="tnum text-ink-900">{{ filledLines }}</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Quantity</dt>
                            <dd class="tnum text-ink-900">{{ pcs(totalQty) }} pcs</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-2.5">
                            <dt class="text-xs text-ink-500">Quoted value</dt>
                            <dd class="text-base font-semibold tnum text-ink-900">
                                {{ money(subtotal, currencyCode) }}
                            </dd>
                        </div>
                    </dl>

                    <!-- The margin floor is the one thing a merchandiser is asked about after
                         sending; it belongs where the total is, not only on the line. -->
                    <p
                        v-if="belowFloor"
                        class="mt-3 rounded bg-amber-50 px-2 py-1.5 text-xs leading-relaxed text-amber-900"
                    >
                        A line is priced below the {{ marginFloorPct }}% margin floor. Sending it needs
                        an approval.
                    </p>
                </Card>

                <Card title="Terms">
                    <FormField label="Terms and conditions" :error="form.errors.terms">
                        <textarea
                            v-model="form.terms"
                            rows="8"
                            class="form-textarea"
                            placeholder="Payment, delivery, validity — whatever this customer has agreed."
                        />
                    </FormField>
                </Card>
            </template>

            <template #footer>
                <FormFooter
                    :form="form"
                    cancel-href="/quotations"
                    :disabled="blockedBy !== null"
                    :disabled-reason="blockedBy"
                    :summary="unpriced.length
                        ? `${unpriced.length} ${unpriced.length === 1 ? 'line is' : 'lines are'} not priced yet — saved as a draft`
                        : `${filledLines} ${filledLines === 1 ? 'line' : 'lines'} · ${money(subtotal, currencyCode)}`"
                    :label="isEdit ? 'Save changes' : 'Save draft'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
