<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import { date, pcs, titleCase } from '@/plugins/formatting';

const props = defineProps({
    orderLines: { type: Array, default: () => [] },
    units: { type: Array, default: () => [] },
    /** The line the planner arrived asking for, resolved and validated server-side. */
    preselectLineId: { type: Number, default: null },
    /** The order the planner came from, when they came from one. */
    context: { type: Object, default: null },
    /** Live cards already covering the preselected line. */
    existingCards: { type: Array, default: () => [] },
});

const preselected = props.orderLines.find((line) => line.id === props.preselectLineId) ?? null;

const form = useForm({
    sales_order_line_id: preselected?.id ?? '',
    factory_unit_id: props.units[0]?.id ?? '',
    // What is left to make on that line, and the date it was promised for: the same defaults
    // picking the line by hand would have applied.
    // BR-49 — the server's headroom, so the form opens on a quantity it would accept.
    planned_qty: preselected ? Number(preselected.capacity?.headroom ?? 0) : '',
    colourway: '',
    due_date: preselected?.promised_date ?? '',
    priority: 50,
});

const selectedLine = computed(() =>
    props.orderLines.find((line) => line.id === Number(form.sales_order_line_id)) ?? null,
);

/**
 * BR-49 — what the chosen line can still take, as the server computed it.
 *
 * Not recomputed here. The planner and the guard must agree on the number, and the way they
 * stop agreeing is a second copy of the arithmetic in JavaScript.
 */
const capacity = computed(() => selectedLine.value?.capacity ?? null);

/** The most a new card may plan on this line. */
const headroom = computed(() => (capacity.value ? Number(capacity.value.headroom) : 0));

/** What is left to make, before the delivery tolerance is considered. */
const outstanding = computed(() => (capacity.value ? Number(capacity.value.outstanding) : 0));

/** The tolerance band adds to the ceiling; say so rather than let it look like a rounding slip. */
const toleranceHeadroom = computed(() => {
    if (!capacity.value) return 0;

    return Math.max(0, Number(capacity.value.allowance) - Number(capacity.value.ordered));
});

/**
 * The refusal the server would give, worked out before the planner presses anything. The
 * server still checks — this is feedback, not the rule.
 */
const quantityError = computed(() => {
    if (!capacity.value || form.planned_qty === '' || form.planned_qty === null) return null;

    const entered = Number(form.planned_qty);

    if (!Number.isFinite(entered) || entered <= 0) return 'Enter a quantity greater than zero.';
    if (entered <= headroom.value) return null;

    if (headroom.value <= 0) {
        return `This line is already fully covered by live job cards — there is nothing left to plan. `
            + `Cancel or reduce an existing card before raising another.`;
    }

    return `${pcs(entered)} pcs is more than this line can take. `
        + `It can absorb ${pcs(headroom.value)} more pcs; reduce the planned quantity to that or less.`;
});

const canSubmit = computed(() => Boolean(form.sales_order_line_id) && quantityError.value === null);

function lineHeadroom(line) {
    return line.capacity ? Number(line.capacity.headroom) : 0;
}

function pickLine(id) {
    form.sales_order_line_id = id;

    const line = props.orderLines.find((candidate) => candidate.id === Number(id));

    if (line) {
        // Default to what the line can actually take, not to a figure the server will refuse.
        form.planned_qty = lineHeadroom(line);
        form.due_date = form.due_date || line.promised_date || '';
    }
}

function submit() {
    if (!canSubmit.value) return;

    form.post('/job-cards');
}
</script>

<template>
    <AppLayout>
        <Head title="New job card" />

        <template #title>New job card</template>
        <template #subtitle>From a confirmed sales order line</template>

        <FormLayout wide-rail @submit="submit">

            <!-- Where the planner came from, and what was chosen on their behalf. -->
            <div
                v-if="context"
                class="rounded-lg border border-brand-200 bg-brand-50 px-3 py-2.5 text-sm text-brand-900"
            >
                <p>
                    Raising a card against
                    <Link :href="`/sales-orders/${context.id}`" class="font-medium underline">
                        order {{ context.number ?? `#${context.id}` }}</Link>
                    for {{ context.customer_name }}.
                    <template v-if="preselected">
                        Line {{ preselected.line_no }} ({{ preselected.product_code }}) is selected with its
                        outstanding quantity.
                    </template>
                    <template v-else-if="context.eligible_lines > 1">
                        {{ context.eligible_lines }} of its lines still have quantity to make — choose one below.
                    </template>
                    <template v-else>
                        None of its lines has quantity left to make.
                    </template>
                </p>
                <p v-if="context.eligible_lines > 1" class="mt-1 text-xs">
                    Lines from other orders are listed underneath, so a card can still be raised against one.
                </p>
            </div>

            <!--
                A line can carry several cards — one per colourway, or a run split in two — so
                this is a statement, not a block. What it prevents is the second card raised by
                accident because the first was nowhere on this screen.
            -->
            <div
                v-if="existingCards.length"
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900"
            >
                <p class="font-medium">
                    {{ existingCards.length }}
                    {{ existingCards.length === 1 ? 'card is' : 'cards are' }} already open against this line.
                </p>
                <ul class="mt-1 space-y-0.5 text-xs">
                    <li v-for="card in existingCards" :key="card.id">
                        <Link :href="`/job-cards/${card.id}`" class="font-medium underline">
                            {{ card.number ?? `draft card #${card.id}` }}</Link>
                        — {{ pcs(card.planned_qty) }} planned<span v-if="card.colourway">, {{ card.colourway }}</span>,
                        {{ titleCase(card.status) }}
                    </li>
                </ul>
            </div>

            <Card title="Order line to produce" :padded="false">
                <div class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
                    <label
                        v-for="line in orderLines"
                        :key="line.id"
                        class="flex cursor-pointer items-start gap-3 px-3 py-2.5 transition hover:bg-slate-50"
                        :class="Number(form.sales_order_line_id) === line.id && 'bg-brand-50'"
                    >
                        <input
                            type="radio"
                            class="form-radio mt-1"
                            :checked="Number(form.sales_order_line_id) === line.id"
                            @change="pickLine(line.id)"
                        >

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 text-sm">
                                <span class="font-medium text-ink-900">{{ line.so_number }}</span>
                                <span class="text-ink-500">line {{ line.line_no }}</span>
                                <span class="font-medium text-ink-800">{{ line.product_code }}</span>
                            </div>
                            <p class="truncate text-xs text-ink-500">
                                {{ line.customer_name }} — {{ line.product_name }}
                            </p>
                        </div>

                        <!--
                            BR-49 — "left" now means what a card may actually plan, which is
                            not the same as the order's outstanding quantity once other live
                            cards hold some of it. A line with nothing left says so rather
                            than reading as available.
                        -->
                        <div class="shrink-0 text-right text-xs">
                            <p
                                class="tnum"
                                :class="lineHeadroom(line) > 0 ? 'text-ink-800' : 'font-medium text-amber-700'"
                            >
                                {{ pcs(lineHeadroom(line)) }} can be planned
                            </p>
                            <p class="tnum text-ink-500">of {{ pcs(line.ordered_qty) }} ordered</p>
                            <p
                                v-if="line.capacity && Number(line.capacity.committed) > 0"
                                class="tnum text-ink-400"
                            >
                                {{ pcs(line.capacity.committed) }} already committed
                            </p>
                            <p v-if="line.promised_date" class="text-ink-400">due {{ date(line.promised_date) }}</p>
                        </div>
                    </label>

                    <div v-if="orderLines.length === 0" class="px-3 py-8">
                        <EmptyState
                            icon="job-card"
                            title="Nothing is waiting to be made"
                            description="A job card is raised against a confirmed order line that still has quantity outstanding. There are none right now."
                            action-label="Open sales orders"
                            action-href="/sales-orders?status=confirmed"
                        />
                    </div>
                </div>

                <p v-if="form.errors.sales_order_line_id" class="border-t border-slate-200 px-3 py-2 text-xs text-rose-600">
                    {{ form.errors.sales_order_line_id }}
                </p>
            </Card>

            <template #rail>
                <Card title="Card">
                    <div class="space-y-3">
                        <FormField label="Factory unit" :error="form.errors.factory_unit_id" required>
                            <SelectInput
                                v-model="form.factory_unit_id"
                                :placeholder="null"
                                :options="units"
                                value-key="id"
                                label-key="name"
                            />
                        </FormField>

                        <!--
                            BR-49. The ceiling is not the bare outstanding quantity: the
                            customer's over-delivery tolerance is a real allowance and is
                            stated rather than quietly folded in. `max` caps the stepper, the
                            hint explains where the number came from, and the message below
                            appears the moment the figure goes over — the server refuses the
                            same quantity with the same reason either way.
                        -->
                        <FormField
                            label="Planned quantity"
                            rule="BR-49"
                            :hint="capacity
                                ? `${pcs(headroom)} pcs can still be planned on this line`
                                : 'Choose an order line first.'"
                            :error="form.errors.planned_qty ?? quantityError"
                            required
                        >
                            <TextInput
                                v-model="form.planned_qty"
                                type="number"
                                numeric
                                min="1"
                                :max="capacity ? headroom : null"
                                :aria-invalid="quantityError ? 'true' : 'false'"
                                aria-describedby="planned-qty-basis"
                            />

                            <p v-if="capacity" id="planned-qty-basis" class="mt-1 text-xs text-ink-500">
                                {{ pcs(capacity.ordered) }} pcs ordered<span v-if="Number(capacity.committed) > 0">,
                                    {{ pcs(capacity.committed) }} pcs already committed to
                                    {{ capacity.live_cards }}
                                    {{ capacity.live_cards === 1 ? 'live card' : 'live cards' }}</span><span
                                        v-if="toleranceHeadroom > 0"
                                    >, plus {{ pcs(toleranceHeadroom) }} pcs of
                                    {{ Number(capacity.over_tolerance_pct) }}% over-delivery tolerance</span>.
                                <span v-if="outstanding !== headroom">
                                    {{ pcs(outstanding) }} pcs outstanding against the order.
                                </span>
                            </p>
                        </FormField>

                        <FormField
                            label="Colourway"
                            rule="BR-28"
                            hint="One card per colourway — a loom cannot weave two at once."
                            :error="form.errors.colourway"
                        >
                            <TextInput v-model="form.colourway" placeholder="White / Navy" />
                        </FormField>

                        <FormField label="Due date" :error="form.errors.due_date">
                            <DateInput v-model="form.due_date" />
                        </FormField>

                        <FormField label="Priority" hint="1 is most urgent; 50 is routine." :error="form.errors.priority">
                            <TextInput v-model="form.priority" type="number" numeric min="1" max="99" />
                        </FormField>
                    </div>
                </Card>

                <!--
                    Gate 1 is structural: `job_cards.artwork_version_id` is NOT NULL, so the
                    controller resolves the approved version and refuses outright if there
                    isn't one. Nothing on this form can bypass it.
                -->
                <Card title="What happens on save" rule="Gate 1 · J1">
                    <ul class="space-y-2 text-xs text-ink-700">
                        <li class="flex gap-2">
                            <Badge tone="info" label="1" />
                            <span>The product's <strong>approved artwork version</strong> is resolved and bound. No approved version, no card.</span>
                        </li>
                        <li class="flex gap-2">
                            <Badge tone="info" label="2" />
                            <span>The consumption plan is computed and <strong>snapshotted</strong> — gross metres, ends, labels per metre.</span>
                        </li>
                        <li class="flex gap-2">
                            <Badge tone="info" label="3" />
                            <span>One operation per routing step is scheduled with its planned minutes.</span>
                        </li>
                        <li class="flex gap-2">
                            <Badge tone="neutral" label="4" />
                            <span>The card is a <strong>draft</strong>. Planning numbers it; the J1 gate governs release.</span>
                        </li>
                    </ul>
                </Card>
            </template>

            <template #footer>
                <!-- BR-49 — a quantity the server would refuse does not get a live button. -->
                <FormFooter
                    :form="form"
                    :disabled="!canSubmit"
                    :disabled-reason="quantityError ?? (form.sales_order_line_id ? null : 'Choose the order line this card is for.')"
                    cancel-href="/job-cards"
                    :label="'Create draft'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
