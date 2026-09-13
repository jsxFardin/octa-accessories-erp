<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DocumentActions from '@/Components/Ui/DocumentActions.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { date, datetime, money, pcs, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';

const props = defineProps({
    jobCard: { type: Object, required: true },
    operations: { type: Array, default: () => [] },
    releaseGate: { type: Object, required: true },
    availableTransitions: { type: Array, default: () => [] },
    bomRequirement: { type: Array, default: () => [] },
    issues: { type: Array, default: () => [] },
    wasteLogs: { type: Array, default: () => [] },
    fgPosition: { type: Object, default: () => ({ produced: 0, received: 0, available: 0, quarantined: 0, remaining_receivable: 0 }) },
    fgReceipts: { type: Array, default: () => [] },
    fgWarehouses: { type: Array, default: () => [] },
    ncrs: { type: Array, default: () => [] },
    operationLogs: { type: Array, default: () => [] },
    operators: { type: Array, default: () => [] },
    shifts: { type: Array, default: () => [] },
    machines: { type: Array, default: () => [] },
});

/*
 * Booking output from the desk.
 *
 * The floor terminal's four endpoints need a badge and a PIN rather than a permission, so a
 * signed-in supervisor could not record a figure at all. Right as a default — output is the
 * shop-floor truth — and unworkable as the only option when the kiosk beside the machine has
 * died mid-shift.
 *
 * Deliberately a narrower door: the shift is stated rather than assumed to be now, the operator
 * is named because the work belongs to whoever did it, and the reason stays on the row.
 */
const mayBook = can('operation.log');
const bookOpen = ref(false);

const bookForm = useForm({
    job_card_operation_id: null,
    good_qty: null,
    waste_qty: null,
    input_qty: null,
    waste_type: null,
    operator_id: null,
    shift_id: null,
    machine_id: null,
    occurred_at: '',
    remarks: '',
    input_override_reason: '',
    manual_reason: '',
});

const WASTE_TYPES = [
    { value: 'setup', label: 'Setup' },
    { value: 'shade', label: 'Shade' },
    { value: 'weave_defect', label: 'Weave defect' },
    { value: 'print_defect', label: 'Print defect' },
    { value: 'cutting', label: 'Cutting' },
    { value: 'edge_trim', label: 'Edge trim' },
    { value: 'damaged', label: 'Damaged' },
    { value: 'expired', label: 'Expired' },
    { value: 'other', label: 'Other' },
];

/*
 * Only steps that can still take production, mirroring `acceptsProduction()` — a completed,
 * skipped or cancelled step is a record, and the service refuses it on both doors.
 *
 * When that leaves nothing, the button says so rather than opening a form with an empty picker
 * and a generic "nothing to choose from". A control that cannot work should explain itself
 * where it is, not after it is pressed.
 */
const bookableOperations = computed(() => props.operations
    .filter((op) => !['completed', 'skipped', 'cancelled'].includes(op.status))
    .map((op) => ({ value: op.id, label: `${op.sequence_no} · ${op.name}`, hint: op.unit })));

/*
 * A booking needs a step that can take it and a person it belongs to. Missing either, the form
 * cannot be completed, and saying so on the button beats opening it to an empty picker.
 *
 * Operators are employee records, which live under Configuration → Lists → Employees rather
 * than under Production — configuration is deliberately kept out of the working sidebar, so
 * the one place that can reasonably point at it is the field that needs it.
 */
const nothingBookable = computed(
    () => bookableOperations.value.length === 0 || props.operators.length === 0,
);

const nothingBookableReason = computed(() => {
    if (props.operators.length === 0) {
        return 'No employees exist yet, so there is nobody to book this against. Add them under '
            + 'Configuration → Lists → Employees — each needs a factory unit, and a card number if '
            + 'they sign in at the floor terminal.';
    }

    if (props.operations.length === 0) return 'This job card has no operations yet.';

    return 'Every step on this card is closed. Production cannot be booked against a completed step — '
        + 'if output is genuinely missing, it has to be reopened first (QC rework reopens a step, '
        + 'or a planner can reset one).';
});

const operatorOptions = computed(() => props.operators.map((e) => ({
    value: e.id,
    label: e.name,
    hint: e.card_no,
})));

/** Now, to the minute, in the browser's own zone — `datetime-local` wants no offset. */
function localNow() {
    return new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

/*
 * Every field set explicitly, and the figures always cleared.
 *
 * `reset()` alone left the previous booking's quantities in the boxes, so reopening the form
 * offered 6,300 good and 200 waste already typed in — one careless Book output away from
 * booking a shift's production twice. Quantities, the waste cause and the reason are the whole
 * content of a booking: they start empty every time, and if that means retyping them, retyping
 * them is the point.
 *
 * Operator, machine and shift are the exception. They do not change from booking to booking
 * within a shift, they are visible in the form before anything is submitted, and clearing them
 * would make the common case — keying several steps off one shift sheet — three extra pickers
 * each time.
 */
function openBooking() {
    bookForm.clearErrors();

    bookForm.job_card_operation_id = bookableOperations.value[0]?.value ?? null;
    bookForm.occurred_at = localNow();

    // The figures. Never carried over.
    bookForm.good_qty = null;
    bookForm.waste_qty = null;
    bookForm.input_qty = null;
    bookForm.waste_type = null;
    bookForm.remarks = '';
    bookForm.manual_reason = '';
    bookForm.input_override_reason = '';

    // Context kept: the same operator on the same machine on the same shift, usually.
    bookForm.operator_id ??= null;
    bookForm.machine_id ??= null;
    bookForm.shift_id ??= null;

    bookOpen.value = true;
}

function submitBooking() {
    bookForm.post(`/job-cards/${props.jobCard.id}/book-output`, {
        preserveScroll: true,
        onSuccess: () => {
            bookOpen.value = false;
            // Cleared here as well as on open: a form left holding a booking that already
            // succeeded is the one a second press would repeat.
            bookForm.good_qty = null;
            bookForm.waste_qty = null;
            bookForm.input_qty = null;
            bookForm.waste_type = null;
            bookForm.remarks = '';
            bookForm.manual_reason = '';
            bookForm.input_override_reason = '';
        },
    });
}

/*
 * I1, applied to production: a correction is a reversing entry, never an edit.
 *
 * Nothing in the application could undo a shift booking. `operation_logs` was written once by
 * the terminal and read by one report; `job_card_operations.good_qty` only accumulated; and
 * the order line's `produced_qty` was incremented by the final operation and decremented by
 * nothing. A mis-keyed 5,000 stayed on the order's fulfilment position for its whole life.
 */
const mayCorrect = can('operation.update');
const reversing = ref(null);
const reversalForm = useForm({ operation_log_id: null, reason: '' });

/*
 * G4 — waste with a cause. `wasteLogs` was declared as a prop, queried on every page load and
 * rendered nowhere, against a table nothing in the codebase ever wrote to.
 */
const wasteColumns = [
    { key: 'occurred_at', label: 'When' },
    { key: 'operation', label: 'Operation' },
    { key: 'waste_type', label: 'Cause' },
    { key: 'qty', label: 'Qty', align: 'right' },
    { key: 'lot_no', label: 'Lot' },
    { key: 'reported_by', label: 'Reported by' },
];

const logColumns = [
    { key: 'started_at', label: 'When' },
    { key: 'operation', label: 'Operation' },
    // Input is on the row now, because a reversal has to take back the same figure it brought.
    { key: 'input_qty', label: 'Input', align: 'right' },
    { key: 'good_qty', label: 'Good', align: 'right' },
    { key: 'waste_qty', label: 'Waste', align: 'right' },
    { key: 'who', label: 'Operator · machine · shift' },
    { key: 'act', label: '', align: 'right', width: '6rem' },
];

/*
 * The unit each figure is counted in.
 *
 * This routing weaves metres and packs pieces, and the table printed both as bare numbers —
 * 106.663 sitting under 6,500 as though they were comparable. The operations table above has
 * carried the unit since the day 407 m read as a catastrophic shortfall against 30,000 pcs;
 * this one was showing the same quantities without it.
 */
const unitByOperation = computed(() => Object.fromEntries(
    props.operations.map((op) => [op.id, op.unit]),
));

function logUnit(row) {
    return unitByOperation.value[row.operation_id] ?? '';
}


function askReverse(row) {
    reversing.value = row;
    reversalForm.defaults({ operation_log_id: row.id, reason: '' });
    reversalForm.reset();
    reversalForm.clearErrors();
}

function submitReversal() {
    reversalForm.post(`/job-cards/${props.jobCard.id}/reverse-log`, {
        preserveScroll: true,
        onSuccess: () => { reversing.value = null; },
    });
}

// P0-3 — client_ref makes a double-submit a replay, not a second lot.
const GRADES = [
    { value: 'A', label: 'A' },
    { value: 'B', label: 'B' },
    { value: 'reject', label: 'Reject' },
];

// The code is what a store keeper types; the name is what tells two "FG-2" apart.
const warehouseOptions = computed(() => props.fgWarehouses.map((warehouse) => ({
    value: warehouse.id,
    label: warehouse.code,
    hint: warehouse.name,
})));

const fgForm = useForm({
    qty: null,
    warehouse_id: props.fgWarehouses[0]?.id ?? null,
    grade: 'A',
    client_ref: (crypto.randomUUID?.() ?? `${Date.now()}-${Math.random()}`),
    material_waiver_reason: '',
});

/**
 * BR-48 — the waiver field is offered whenever the rule could refuse this receipt, to someone
 * holding the permission it names.
 *
 * "Could" rather than "will": keying it off the typed quantity alone hid the field at the one
 * moment it is needed, the refusal, because the refused quantity is not in the box any more.
 * So it appears as soon as the issued material fails to cover everything still receivable —
 * and always after a refusal that named it.
 */
/**
 * The statuses `FgReceiptService` will take a receipt from. Mirrored here so the form is
 * absent rather than refused — a closed card is terminal, and offering it a receipt button
 * suggests a recovery route the application does not have.
 */
const FG_RECEIVABLE_STATUSES = ['in_production', 'qc_pending', 'completed'];

const canReceiveFg = computed(() => FG_RECEIVABLE_STATUSES.includes(props.jobCard.status));

const needsMaterialWaiver = computed(() => {
    // The permission the rules name. Without it there is nothing to offer: the waiver would
    // be refused server-side anyway, and a field that cannot be used is worse than none.
    if (!can('job_card.waive_material')) return false;

    // After a refusal that named the waiver, always — whichever rule raised it.
    if (fgForm.errors.material_waiver_reason || fgForm.errors.qty) return true;

    // BR-52 — the receipt would take stock in at no value, because nothing issued to this job
    // carries a cost. This is the case BR-48 says nothing about: a job whose BOM has nothing
    // mandatory on it requires no issue, so `material_required` is false, and the waiver used
    // to stay hidden for exactly the receipts that need it. The rule told the supervisor to
    // record a waiver and the form gave them nowhere to record it.
    const receivable = Number(props.fgPosition.remaining_receivable ?? 0);
    const typed = Number(fgForm.qty ?? 0);

    if (Number(props.fgPosition.unit_cost ?? 0) <= 0 && (receivable > 0 || typed > 0)) return true;

    // BR-48 — the issued material does not stretch to what is being received.
    if (!props.fgPosition.material_required) return false;

    const covered = Number(props.fgPosition.material_supports ?? 0);
    const received = Number(props.fgPosition.received ?? 0);

    // Short for the whole remaining run, or short for what is actually being keyed in.
    return received + receivable > covered + 0.000001 || received + typed > covered + 0.000001;
});

function postFgReceipt() {
    fgForm.post(`/job-cards/${props.jobCard.id}/fg-receipts`, {
        preserveScroll: true,
        onSuccess: () => {
            fgForm.reset('qty', 'grade');
            fgForm.client_ref = crypto.randomUUID?.() ?? `${Date.now()}-${Math.random()}`;
        },
    });
}

const releaseOpen = ref(false);
const holdOpen = ref(false);

const releaseForm = useForm({ to: 'released', material_waiver_reason: '' });

const completeOpen = ref(false);
const completeForm = useForm({ to: 'completed', material_waiver_reason: '' });

/** I7 will refuse completion: a mandatory BOM item has had nothing issued against it. */
const completeUnissued = computed(
    () => props.fgPosition.material_required && Number(props.fgPosition.material_supports ?? 0) <= 0,
);

function completeWithWaiver() {
    completeForm.post(`/job-cards/${props.jobCard.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => {
            completeOpen.value = false;
            completeForm.reset('material_waiver_reason');
        },
    });
}

/**
 * P0-3 — closing is the last thing that happens to a card, and `closed` has no way back. Any
 * output not received into finished goods by then is stranded: the pieces exist on the job and
 * nowhere in stock, and the order they were made for cannot be packed. So the close asks.
 */
const closeOpen = ref(false);
const closeForm = useForm({ to: 'closed', unreceived_output_reason: '' });

const closeUnreceived = computed(() => Number(props.fgPosition.remaining_receivable ?? 0) > 0);

function closeCard() {
    if (closeUnreceived.value) {
        closeOpen.value = true;

        return;
    }

    transition('closed');
}

function closeWithReason() {
    closeForm.post(`/job-cards/${props.jobCard.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => {
            closeOpen.value = false;
            closeForm.reset('unreceived_output_reason');
        },
    });
}

/**
 * J6 — abandoning a card. The state machine has always allowed this from draft, planned and
 * released, and `guardCancelled()` was written to demand a supervisor's reason once production
 * had been logged. No screen ever offered it, so that guard had never run and a card raised in
 * error stayed open for good — holding the order line's BR-49 headroom the whole time, which
 * is what stops a replacement card being raised.
 */
const cancelOpen = ref(false);
const cancelForm = useForm({ to: 'cancelled', reason: '' });

/** Anything booked at all — the cross-unit running total is the right question here (J6). */
const cancelNeedsReason = computed(() => Number(props.jobCard.produced_qty_running ?? 0) > 0);

function cancelCard() {
    if (cancelNeedsReason.value) {
        cancelOpen.value = true;

        return;
    }

    transition('cancelled');
}

function cancelWithReason() {
    cancelForm.post(`/job-cards/${props.jobCard.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelOpen.value = false;
            cancelForm.reset('reason');
        },
    });
}

/** P0-3 — the one way out of `closed`, so unreceived output is recoverable rather than lost. */
const reopenOpen = ref(false);
const reopenForm = useForm({ to: 'completed', reopen_reason: '' });

function reopen() {
    reopenForm.post(`/job-cards/${props.jobCard.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => {
            reopenOpen.value = false;
            reopenForm.reset('reopen_reason');
        },
    });
}
const holdForm = useForm({ to: 'on_hold', hold_reason: '' });

const checks = computed(() => Object.values(props.releaseGate.checks));

const progressPct = computed(() => {
    const planned = Number(props.jobCard.planned_qty) || 1;

    return Math.min(100, (Number(props.jobCard.good_qty) / planned) * 100);
});

const overrunBreached = computed(
    () => Number(props.jobCard.produced_qty) > Number(props.jobCard.overrun_ceiling),
);

const confirmTransition = useTransitionConfirm();

async function transition(to) {
    if (!(await confirmTransition(to, props.jobCard.number))) return;

    router.post(`/job-cards/${props.jobCard.id}/transition`, { to }, { preserveScroll: true });
}

function release() {
    releaseForm.post(`/job-cards/${props.jobCard.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => {
            releaseOpen.value = false;
            releaseForm.reset();
        },
    });
}

function hold() {
    holdForm.post(`/job-cards/${props.jobCard.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => {
            holdOpen.value = false;
            holdForm.reset();
        },
    });
}

/**
 * A card at `qc_pending` is literally waiting for an inspection; one in production can be
 * inspected in-process. Both used to mean walking to Quality and finding the card by number.
 */
const canInspect = computed(
    () => ['in_production', 'qc_pending'].includes(props.jobCard.status) && can('qc_inspection.create'),
);

/**
 * Material moves against a card that is released or running — the same two statuses the issue
 * form itself allows. A card stuck at `material_pending` is the single biggest reason the
 * floor is idle, and the way to clear it was to leave the card and search for it again.
 */
const canIssueMaterial = computed(
    () => ['released', 'in_production', 'material_pending'].includes(props.jobCard.status)
        && can('stock_issue.create'),
);

const issueHref = computed(() => `/material-issues/create?job_card=${props.jobCard.id}`);

function inspectionHref(operation = null) {
    const base = `/qc-inspections/create?job_card=${props.jobCard.id}`;

    return operation ? `${base}&operation=${operation.id}&stage=in_process` : base;
}

const operationColumns = [
    { key: 'sequence_no', label: '#', align: 'center', width: '3rem' },
    { key: 'name', label: 'Operation' },
    { key: 'machine', label: 'Machine' },
    { key: 'planned_qty', label: 'Planned', align: 'right' },
    { key: 'input_qty', label: 'Input', align: 'right' },
    { key: 'good_qty', label: 'Good', align: 'right' },
    { key: 'waste_qty', label: 'Waste', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'inspect', label: '', width: '5.5rem', align: 'right' },
];

const bomColumns = [
    { key: 'item', label: 'Item' },
    { key: 'qty_per_base', label: 'Per 1000', align: 'right' },
    { key: 'required', label: 'Required', align: 'right' },
    { key: 'formula_ref', label: 'Rule' },
];
</script>

<template>
    <AppLayout :crumb="jobCard.number ?? 'Draft job card'">
        <Head :title="jobCard.number ?? 'Job card'" />

        <template #title>{{ jobCard.number ?? '(unnumbered)' }}</template>
        <template #subtitle>
            {{ jobCard.product?.code }} — {{ jobCard.product?.name }}
            <span v-if="jobCard.colourway"> · {{ jobCard.colourway }}</span>
            <span v-if="jobCard.customer"> · {{ jobCard.customer.name }}</span>
            <!-- The order this card is making, one click away rather than a search. -->
            <span v-if="jobCard.sales_order">
                ·
                <Link :href="`/sales-orders/${jobCard.sales_order.id}`" class="doc-link">
                    {{ jobCard.sales_order.number ?? '(unnumbered order)' }}</Link><span
                    v-if="jobCard.sales_order_line_no"> line {{ jobCard.sales_order_line_no }}</span>
            </span>
        </template>

        <!-- Status, then the transition this card is waiting for, then everything else. -->
        <template #actions>
            <Badge :status="jobCard.status" />

            <Button
                v-if="availableTransitions.includes('planned')"
                size="sm"
                @click="transition('planned')"
            >
                Plan
            </Button>
            <Button
                v-if="availableTransitions.includes('released')"
                size="sm"
                variant="primary"
                @click="releaseOpen = true"
            >
                Release
            </Button>
            <Button v-if="availableTransitions.includes('in_production')" size="sm" @click="transition('in_production')">
                Resume
            </Button>
            <!--
                What a card in production is actually waiting for. Never primary at the same
                time as `Record inspection`: once the card is at `qc_pending` this transition
                is no longer available, so exactly one of the two leads at any status.
            -->
            <Button v-if="availableTransitions.includes('qc_pending')" size="sm" variant="primary" @click="transition('qc_pending')">
                Send to QC
            </Button>
            <!--
                I7 refuses completion when a mandatory BOM item had nothing issued, and tells
                the supervisor to "complete with a documented waiver" — so when that is going
                to happen, the click opens somewhere to write one instead of a dead refusal.
            -->
            <Button
                v-if="availableTransitions.includes('completed')"
                size="sm"
                variant="success"
                @click="completeUnissued ? (completeOpen = true) : transition('completed')"
            >
                Complete
            </Button>
            <Button v-if="availableTransitions.includes('closed')" size="sm" @click="closeCard">
                Close
            </Button>
            <!--
                Only offered on a closed card, and only where there is something to go back
                for: reopening a card whose output is all in stock changes nothing and invites
                a status being flipped for no reason.
            -->
            <Button
                v-if="jobCard.status === 'closed' && availableTransitions.includes('completed')"
                size="sm"
                :variant="fgPosition.remaining_receivable > 0 ? 'primary' : 'secondary'"
                @click="reopenOpen = true"
            >
                Reopen
            </Button>

            <!-- The card is already known; QC opens with it chosen and its output as the lot. -->
            <Button
                v-if="canInspect"
                size="sm"
                :variant="jobCard.status === 'qc_pending' ? 'primary' : 'secondary'"
                :href="inspectionHref()"
            >
                Record inspection
            </Button>

            <DocumentActions document="job-cards" :id="jobCard.id" :status="jobCard.status" />

            <!-- Destructive last, after everything that moves the card forward. -->
            <Button v-if="availableTransitions.includes('on_hold')" size="sm" variant="danger" @click="holdOpen = true">
                Hold
            </Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="cancelCard">
                Cancel card
            </Button>
        </template>

        <div class="space-y-4">
            <!-- J1: the four conditions, always visible -->
            <Card
                title="Release gate"
                rule="J1"
                subtitle="All four must hold before production may run. Shown whether or not you are about to release."
            >
                <ul class="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                    <li
                        v-for="check in checks"
                        :key="check.label"
                        class="rounded-md border px-3 py-2"
                        :class="check.ok ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50'"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium" :class="check.ok ? 'text-emerald-900' : 'text-rose-900'">
                                {{ check.label }}
                            </span>
                            <span class="font-mono text-[10px]" :class="check.ok ? 'text-emerald-700' : 'text-rose-700'">
                                {{ check.rule }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs" :class="check.ok ? 'text-emerald-800' : 'text-rose-800'">
                            {{ check.detail }}
                        </p>
                    </li>
                </ul>

                <div v-if="releaseGate.shortages.length" class="mt-3">
                    <p class="mb-1 text-xs font-medium text-ink-700">Shortages</p>
                    <table class="min-w-full text-xs">
                        <thead class="text-ink-500">
                            <tr>
                                <th class="py-1 text-left">Item</th>
                                <th class="py-1 text-right">Required</th>
                                <th class="py-1 text-right">Available</th>
                                <th class="py-1 text-right">On order</th>
                                <th class="py-1 text-right">Short</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="shortage in releaseGate.shortages" :key="shortage.item_id" class="border-t border-slate-100">
                                <td class="py-1">{{ shortage.item_code }} — {{ shortage.item_name }}</td>
                                <td class="py-1 text-right tnum">{{ qty(shortage.required) }}</td>
                                <td class="py-1 text-right tnum">{{ qty(shortage.available) }}</td>
                                <td class="py-1 text-right tnum">{{ qty(shortage.on_order) }}</td>
                                <td class="py-1 text-right font-medium tnum text-rose-600">{{ qty(shortage.short) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <div class="grid gap-4 xl:grid-cols-3">
                <!-- Consumption snapshot -->
                <Card
                    title="Consumption plan"
                    rule="BR-4 … BR-13"
                    subtitle="Snapshotted at planning. A later spec revision does not change what the floor produces to."
                >
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-ink-500">Planned quantity</dt>
                            <dd class="font-medium tnum text-ink-900">{{ pcs(jobCard.planned_qty) }} pcs</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Gross metres</dt>
                            <dd class="font-medium tnum text-ink-900">{{ qty(jobCard.gross_metres) }} m</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Ends</dt>
                            <dd class="font-medium tnum text-ink-900">{{ jobCard.ends ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Labels / metre</dt>
                            <dd class="font-medium tnum text-ink-900">{{ qty(jobCard.labels_per_metre, 4) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Spec version</dt>
                            <dd class="font-medium text-ink-900">v{{ jobCard.spec_version }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Due</dt>
                            <dd class="font-medium text-ink-900">{{ date(jobCard.due_date) }}</dd>
                        </div>
                    </dl>
                </Card>

                <!-- Gate 1 binding -->
                <Card title="Bound artwork" rule="Gate 1 · A2">
                    <div class="space-y-2 text-sm">
                        <div class="flex items-center gap-2">
                            <Link :href="`/artworks/${jobCard.artwork.id}`" class="doc-link-quiet">
                                {{ jobCard.artwork.code }} v{{ jobCard.artwork.version_no }}
                            </Link>
                            <Badge :status="jobCard.artwork.status" />
                        </div>
                        <p class="font-mono text-[10px] break-all text-ink-400">
                            sha256 {{ jobCard.artwork.checksum }}
                        </p>
                        <p class="text-xs text-ink-500">
                            This job card is welded to this version. Superseding it upstream does not
                            change what this run prints.
                        </p>
                    </div>
                </Card>

                <!-- Output -->
                <Card title="Output" rule="J3 · J5">
                    <div class="mb-3">
                        <div class="mb-1 flex items-center justify-between text-xs text-ink-500">
                            <span>Good against planned</span>
                            <span class="tnum">{{ pcs(jobCard.good_qty) }} / {{ pcs(jobCard.planned_qty) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${progressPct}%` }" />
                        </div>
                    </div>

                    <dl class="grid grid-cols-3 gap-2 text-sm">
                        <div>
                            <dt class="text-xs text-ink-500">Produced</dt>
                            <dd class="font-medium tnum">{{ pcs(jobCard.produced_qty) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Good</dt>
                            <dd class="font-medium tnum text-emerald-700">{{ pcs(jobCard.good_qty) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-500">Waste</dt>
                            <dd class="font-medium tnum text-rose-600">{{ pcs(jobCard.waste_qty) }}</dd>
                        </div>
                    </dl>

                    <p
                        class="mt-3 rounded px-2 py-1 text-xs"
                        :class="overrunBreached ? 'bg-rose-50 text-rose-800' : 'bg-slate-50 text-ink-700'"
                    >
                        J5 ceiling: {{ pcs(jobCard.overrun_ceiling) }} pcs
                        ({{ jobCard.overrun_tolerance_pct }}% overrun tolerance)
                    </p>
                </Card>
            </div>

            <!--
                Where the waste went. The operation row carries a waste figure; this says what
                the waste was, which is the half that can be acted on.
            -->
            <Card
                title="Waste"
                rule="G4"
                subtitle="Booked from the floor with its cause. Setup, shade and a weave defect are three different problems."
                :padded="false"
            >
                <DataTable :columns="wasteColumns" :rows="wasteLogs" empty="No waste has been booked against this job card." dense>
                    <template #cell:occurred_at="{ value }">{{ datetime(value) }}</template>
                    <template #cell:operation="{ row }">
                        <span class="text-ink-700">{{ row.sequence_no }} · {{ row.operation }}</span>
                    </template>
                    <template #cell:waste_type="{ value }"><Badge tone="warning" :label="titleCase(value)" /></template>
                    <template #cell:qty="{ row, value }">
                        <span class="tnum text-rose-600">{{ qty(value) }}</span>
                        <span class="text-ink-400"> {{ row.uom ?? '' }}</span>
                    </template>
                    <template #cell:lot_no="{ value }">{{ value ?? '—' }}</template>
                    <template #cell:reported_by="{ value }">{{ value ?? '—' }}</template>
                </DataTable>
            </Card>

            <!--
                The shift bookings behind those totals, and the only way to correct one. The
                card showed that a step held 5,000 with no way to see which shift booked it,
                who booked it, or that it was one mis-keyed entry.
            -->
            <Card
                title="Shift bookings"
                rule="I1"
                subtitle="What the floor recorded, newest first. A correction is a reversing entry — the original row stays as booked."
                :padded="false"
            >
                <template #actions>
                    <!--
                        The way in when the kiosk is down. Not the normal path, and it does not
                        look like one: it asks which shift, which operator, and why.
                    -->
                    <Button
                        v-if="mayBook"
                        size="sm"
                        variant="secondary"
                        :disabled="nothingBookable"
                        :title="nothingBookable ? nothingBookableReason : 'Key a booking the terminal could not take'"
                        @click="openBooking"
                    >
                        Book output manually
                    </Button>
                </template>
                <DataTable :columns="logColumns" :rows="operationLogs" empty="Nothing has been booked from the floor yet." dense>
                    <template #cell:started_at="{ row, value }">
                        <div :class="row.is_reversed ? 'text-ink-400' : 'text-ink-700'">{{ datetime(value) }}</div>
                        <!--
                            A reversal carries the window of the booking it cancels, so the two
                            rows show the same time. When it was actually keyed is a different
                            fact and belongs on the row that was keyed.
                        -->
                        <div v-if="row.reverses_log_id" class="text-[11px] text-ink-500">
                            reversed {{ datetime(row.created_at) }}
                        </div>
                    </template>

                    <template #cell:operation="{ row }">
                        <div class="flex items-center gap-1.5">
                            <span :class="row.is_reversed ? 'text-ink-400 line-through' : 'text-ink-800'">
                                {{ row.sequence_no }} · {{ row.operation }}
                            </span>
                            <Badge v-if="row.reverses_log_id" tone="warning" label="reversal" />
                            <Badge v-else-if="row.is_reversed" tone="neutral" label="reversed" />
                            <Badge v-if="row.manual_reason" tone="info" label="desk" />
                        </div>

                        <!--
                            The reasons, read rather than hovered. A correction with a stated
                            reason is only useful if the reason is on the screen next to it.
                        -->
                        <p v-if="row.reversal_reason" class="mt-0.5 text-[11px] text-amber-800">
                            Reversed: {{ row.reversal_reason }}
                        </p>
                        <p v-if="row.manual_reason" class="mt-0.5 text-[11px] text-ink-500">
                            Keyed by {{ row.entered_by ?? 'a desk user' }}: {{ row.manual_reason }}
                        </p>
                        <p v-if="row.remarks" class="mt-0.5 text-[11px] text-ink-500">{{ row.remarks }}</p>
                    </template>

                    <template #cell:input_qty="{ row, value }">
                        <span class="tnum text-ink-600">{{ qty(value) }}</span>
                        <span class="text-[11px] text-ink-400"> {{ logUnit(row) }}</span>
                    </template>
                    <template #cell:good_qty="{ row, value }">
                        <span class="tnum" :class="Number(value) < 0 ? 'text-amber-700' : 'text-emerald-700'">{{ qty(value) }}</span>
                        <span class="text-[11px] text-ink-400"> {{ logUnit(row) }}</span>
                    </template>
                    <template #cell:waste_qty="{ row, value }">
                        <span class="tnum" :class="Number(value) < 0 ? 'text-amber-700' : 'text-rose-600'">{{ qty(value) }}</span>
                        <span class="text-[11px] text-ink-400"> {{ logUnit(row) }}</span>
                    </template>

                    <!--
                        Three columns of mostly-repeating text became one line. Operator,
                        machine and shift are the same for every booking on a run, and reading
                        the same three values down forty rows is how a table stops being read.
                    -->
                    <template #cell:who="{ row }">
                        <span class="text-ink-700">{{ row.operator ?? '—' }}</span>
                        <span v-if="row.machine" class="text-ink-400"> · {{ row.machine }}</span>
                        <span v-if="row.shift" class="text-ink-400"> · {{ row.shift }}</span>
                    </template>

                    <template #cell:act="{ row }">
                        <Button
                            v-if="mayCorrect && !row.reverses_log_id && !row.is_reversed"
                            size="sm"
                            variant="ghost"
                            @click="askReverse(row)"
                        >
                            Reverse
                        </Button>
                    </template>
                </DataTable>
            </Card>

            <!-- Operations -->
            <Card title="Operations" rule="J2" subtitle="Execute in sequence; a step cannot start before its predecessor closes" :padded="false">
                <DataTable :columns="operationColumns" :rows="operations" empty="No operations scheduled." dense>
                    <template #cell:name="{ row }">
                        <span class="font-medium text-ink-800">{{ row.name }}</span>
                        <Badge v-if="row.requires_qc" tone="info" label="QC" class="ml-1" />
                        <Badge v-if="!row.predecessors_complete" tone="neutral" label="blocked" class="ml-1" />
                    </template>
                    <template #cell:machine="{ row }">{{ row.machine?.code ?? row.machine_group ?? '—' }}</template>
                    <!--
                        Every operation quantity carries its unit. Weaving books metres and
                        packing books pieces, so a bare column of numbers invites the reader
                        to add them up — which is how "60,457 good against 30,000 planned"
                        was ever printed for a job that made exactly 30,000 labels.
                    -->
                    <template #cell:planned_qty="{ row, value }">{{ qty(value) }} <span class="text-ink-400">{{ row.unit }}</span></template>
                    <template #cell:input_qty="{ row, value }">{{ qty(value) }} <span class="text-ink-400">{{ row.unit }}</span></template>
                    <template #cell:good_qty="{ row, value }">{{ qty(value) }} <span class="text-ink-400">{{ row.unit }}</span></template>
                    <template #cell:waste_qty="{ row, value }">{{ qty(value) }} <span class="text-ink-400">{{ row.unit }}</span></template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                    <!--
                        QC1 — an in-process verdict releases exactly one operation, so the
                        inspection is raised from the operation rather than picked out of a
                        flat list of every QC step in the factory.
                    -->
                    <template #cell:inspect="{ row }">
                        <Button
                            v-if="canInspect && row.requires_qc && row.status !== 'pending'"
                            size="sm"
                            :href="inspectionHref(row)"
                        >
                            Inspect
                        </Button>
                    </template>
                </DataTable>
            </Card>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card title="Material requirement" rule="BR-1" subtitle="BOM scaled from per-1000 to this job's quantity" :padded="false">
                    <DataTable :columns="bomColumns" :rows="bomRequirement" empty="No BOM bound." dense>
                        <template #cell:item="{ row }">
                            <span class="font-medium">{{ row.item?.code }}</span>
                            <span class="text-ink-500"> {{ row.item?.name }}</span>
                        </template>
                        <template #cell:qty_per_base="{ value }">{{ qty(value) }}</template>
                        <template #cell:required="{ value }">{{ qty(value) }}</template>
                        <template #cell:formula_ref="{ value }">
                            <span v-if="value" class="rounded bg-slate-100 px-1 font-mono text-[10px]">{{ value }}</span>
                            <span v-else class="text-ink-400">fixed</span>
                        </template>
                    </DataTable>
                </Card>

                <Card title="Material issued" :padded="false">
                    <template #actions>
                        <Button v-if="canIssueMaterial" size="sm" :href="issueHref">Issue material</Button>
                    </template>

                    <ul class="divide-y divide-slate-100 text-sm">
                        <!-- Each issue now opens: which lots it moved is the shade-traceability
                             record, and it used to be a dead line of text. -->
                        <li v-for="issue in issues" :key="issue.id" class="flex items-center justify-between px-3 py-2">
                            <Link :href="`/material-issues/${issue.id}`" class="doc-link-quiet">
                                {{ issue.number ?? `issue #${issue.id}` }}
                            </Link>
                            <span class="text-xs text-ink-500">{{ date(issue.issued_on) }}</span>
                            <Badge :status="issue.status" />
                        </li>
                        <li v-if="issues.length === 0" class="px-3 py-4">
                            <EmptyState
                                icon="issue"
                                title="No material issued to this card yet"
                                :description="canIssueMaterial
                                    ? 'Production cannot draw against it until material is issued from a store.'
                                    : 'Material is issued once the card has been released to the floor.'"
                                :action-label="canIssueMaterial ? 'Issue material' : null"
                                :action-href="canIssueMaterial ? issueHref : null"
                            />
                        </li>
                    </ul>
                </Card>
            </div>

            <!-- P0-3: production output becomes stock here. The gap is stated, never smoothed. -->
            <Card title="Finished goods" rule="P0-3" subtitle="Output enters FG stock through a receipt; quarantine until final QC accepts">
                <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
                    <div>
                        <dt class="text-xs text-ink-500">Produced (final op)</dt>
                        <dd class="font-medium tnum">{{ pcs(fgPosition.produced) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Received to FG</dt>
                        <dd class="font-medium tnum">{{ pcs(fgPosition.received) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Available</dt>
                        <dd class="font-medium tnum text-emerald-700">{{ pcs(fgPosition.available) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">In quarantine</dt>
                        <dd class="font-medium tnum text-amber-700">{{ pcs(fgPosition.quarantined) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Unreceived production</dt>
                        <dd class="font-medium tnum" :class="fgPosition.remaining_receivable > 0 ? 'text-rose-600' : ''">
                            {{ pcs(fgPosition.remaining_receivable) }}
                        </dd>
                    </div>
                </dl>

                <!--
                    BR-48 — what the issued material can account for, and what a piece
                    of this job is therefore worth. A lot valued at 0.00 is a job with nothing
                    issued against it, and that is worth saying on the screen rather than
                    leaving someone to discover it in a stock valuation.
                -->
                <dl
                    v-if="fgPosition.material_required"
                    class="mt-3 grid grid-cols-2 gap-2 border-t border-slate-100 pt-3 text-sm sm:grid-cols-5"
                >
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-ink-500">Material covers</dt>
                        <dd
                            class="font-medium tnum"
                            :class="fgPosition.material_issued_any ? '' : 'text-rose-600'"
                        >
                            <template v-if="fgPosition.material_issued_any">{{ pcs(fgPosition.material_supports) }} pcs</template>
                            <template v-else>Nothing issued</template>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">FG unit cost</dt>
                        <dd class="font-medium tnum" :class="Number(fgPosition.unit_cost) > 0 ? '' : 'text-rose-600'">
                            {{ money(fgPosition.unit_cost) }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dd v-if="!fgPosition.material_issued_any" class="text-xs text-rose-600">
                            No material has been issued to this job, so finished goods cannot be
                            received from it and would value at zero.
                            <Link :href="`/material-issues/create?job_card=${jobCard.id}`" class="underline">Issue material</Link>.
                        </dd>
                    </div>
                </dl>

                <ul v-if="fgReceipts.length" class="mt-3 divide-y divide-slate-100 border-t border-slate-100 text-sm">
                    <li v-for="receipt in fgReceipts" :key="receipt.id" class="flex items-center justify-between gap-2 py-2">
                        <span class="font-medium text-ink-800">{{ receipt.number }}</span>
                        <span class="tnum">{{ pcs(receipt.qty) }}</span>
                        <Link
                            v-if="receipt.lot_id"
                            :href="`/lots/${receipt.lot_id}`"
                            class="text-xs text-brand-700 hover:underline"
                        >lot {{ receipt.lot_no }}</Link>
                        <span v-else class="text-xs text-ink-500">lot —</span>
                        <Badge v-if="receipt.grade !== 'A'" tone="warning" :label="`grade ${receipt.grade}`" />
                        <Badge :status="receipt.lot_status ?? receipt.status" />
                        <span class="text-xs text-ink-500">{{ date(receipt.received_on) }}</span>
                    </li>
                </ul>

                <!--
                    P0-3 — a card whose status cannot take a receipt says so instead of
                    offering a form that the service will refuse. `closed` is the one that
                    matters: it is terminal and it cannot receive, so output left unreceived
                    there is stranded, and a live-looking form on that screen reads as a way
                    out that does not exist.
                -->
                <p
                    v-if="!canReceiveFg && fgPosition.remaining_receivable > 0"
                    class="mt-3 border-t border-slate-100 pt-3 text-xs text-rose-700"
                >
                    {{ pcs(fgPosition.remaining_receivable) }} unreceived. Finished goods cannot be
                    received from a job card that is {{ titleCase(jobCard.status) }}.
                </p>

                <form
                    v-if="canReceiveFg && can('fg_receipt.post') && fgPosition.remaining_receivable > 0"
                    class="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3"
                    @submit.prevent="postFgReceipt"
                >
                    <FormField
                        label="Quantity"
                        :error="fgForm.errors.qty"
                        rule="P0-3"
                        class="w-36"
                    >
                        <TextInput
                            v-model="fgForm.qty"
                            type="number"
                            min="0.000001"
                            step="any"
                            numeric
                            :max="fgPosition.remaining_receivable"
                            :placeholder="`≤ ${fgPosition.remaining_receivable}`"
                        />
                    </FormField>
                    <FormField label="Warehouse" :error="fgForm.errors.warehouse_id" class="w-52">
                        <SelectInput v-model="fgForm.warehouse_id" :options="warehouseOptions" hint-key="hint" :placeholder="null" />
                    </FormField>
                    <FormField label="Grade" :error="fgForm.errors.grade" class="w-28">
                        <SelectInput v-model="fgForm.grade" :options="GRADES" :placeholder="null" />
                    </FormField>
                    <!--
                        BR-48 and BR-52 both refuse a receipt and both name the same remedy —
                        "record a waiver with a reason" — so there has to be somewhere to record
                        it, in both cases. Offered when the material falls short *or* when the
                        goods would enter stock at no value, and only to someone holding the
                        permission the rules name.
                    -->
                    <FormField
                        v-if="needsMaterialWaiver"
                        label="Material waiver reason"
                        :error="fgForm.errors.material_waiver_reason"
                        :rule="Number(fgPosition.unit_cost ?? 0) <= 0 ? 'BR-52' : 'BR-48'"
                        class="w-full sm:w-96"
                        :hint="Number(fgPosition.unit_cost ?? 0) <= 0
                            ? 'Nothing issued to this job carries a cost, so these goods would enter stock at no value. Say why.'
                            : 'Material issued covers less than this receipt. Say why it is being received anyway.'"
                    >
                        <TextInput v-model="fgForm.material_waiver_reason" placeholder="Rework fed from a previous run…" />
                    </FormField>
                    <Button type="submit" size="sm" variant="primary" :loading="fgForm.processing" :disabled="fgForm.processing">Receive to FG</Button>
                </form>
            </Card>

            <Card v-if="ncrs.length" title="NCRs" subtitle="Raised when QC rejected output from this job">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="ncr in ncrs" :key="ncr.id" class="flex items-center justify-between py-2">
                        <Link :href="`/ncrs/${ncr.id}`" class="doc-link-quiet">{{ ncr.number }}</Link>
                        <Badge
                            :tone="ncr.severity === 'critical' ? 'danger' : ncr.severity === 'major' ? 'warning' : 'neutral'"
                            :label="titleCase(ncr.severity)"
                        />
                        <Badge :status="ncr.status" />
                        <span class="text-xs text-ink-500">{{ date(ncr.raised_on) }}</span>
                    </li>
                </ul>
            </Card>
        </div>

        <!-- Release: the waiver is the only way past a shortage, and it demands a reason -->
        <Modal
            v-model:open="releaseOpen"
            title="Release this job card"
            subtitle="J1: approved artwork, active BOM, tools available, material in stock or waived."
            width="max-w-xl"
        >
            <div v-if="releaseGate.ready" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                All four conditions hold. Releasing reserves stock and puts the first operation on the floor queue.
            </div>

            <div v-else class="space-y-3">
                <div class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900">
                    <p class="font-medium">This job card is not ready.</p>
                    <ul class="mt-1 list-disc pl-4 text-xs">
                        <li v-for="check in checks.filter((c) => !c.ok)" :key="check.label">
                            {{ check.label }} ({{ check.rule }}) — {{ check.detail }}
                        </li>
                    </ul>
                </div>

                <FormField
                    v-if="releaseGate.shortages.length"
                    label="Material waiver reason"
                    rule="J1"
                    hint="Only material shortages can be waived, and only with a reason and the job_card.waive_material permission. Artwork cannot."
                    :error="releaseForm.errors.material_waiver_reason"
                >
                    <textarea v-model="releaseForm.material_waiver_reason" rows="2" class="form-textarea" />
                </FormField>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="releaseForm.processing" @click="release">Release</Button>
            </template>
        </Modal>

        <Modal
            v-model:open="completeOpen"
            title="Complete this job card"
            subtitle="I7: nothing was issued against part of this job's BOM."
        >
            <div class="space-y-3">
                <p class="text-sm text-ink-700">
                    A job that ran on substitutes, or on material issued through another route, is a
                    real thing. Completing it anyway needs the <code>job_card.waive_material</code>
                    permission and a sentence saying what happened.
                </p>

                <FormField
                    label="Material waiver reason"
                    rule="I7"
                    required
                    :error="completeForm.errors.material_waiver_reason"
                >
                    <textarea v-model="completeForm.material_waiver_reason" rows="2" class="form-textarea" />
                </FormField>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="success"
                    :loading="completeForm.processing"
                    :disabled="!completeForm.material_waiver_reason"
                    @click="completeWithWaiver"
                >Complete</Button>
            </template>
        </Modal>

        <Modal
            v-model:open="closeOpen"
            title="Close with output still unreceived"
            subtitle="P0-3: a closed card cannot receive finished goods, and cannot be reopened."
        >
            <div class="space-y-3">
                <p class="text-sm text-ink-700">
                    <strong>{{ pcs(fgPosition.remaining_receivable) }}</strong> of this job's output
                    has never been received into stock. Closing now leaves it on the job card and
                    nowhere in inventory — the order it was made for cannot be packed from it, and
                    there is no way back. Receive it first unless it is genuinely not being stocked.
                </p>

                <FormField
                    label="Why is the output not being stocked?"
                    rule="P0-3"
                    required
                    hint="Scrapped after final QC, written off, absorbed by another job — say which."
                    :error="closeForm.errors.unreceived_output_reason"
                >
                    <textarea v-model="closeForm.unreceived_output_reason" rows="2" class="form-textarea" />
                </FormField>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Go back and receive</Button>
                <Button
                    variant="danger"
                    :loading="closeForm.processing"
                    :disabled="!closeForm.unreceived_output_reason"
                    @click="closeWithReason"
                >Close anyway</Button>
            </template>
        </Modal>

        <Modal
            v-model:open="reopenOpen"
            title="Reopen this job card"
            subtitle="P0-3: back to completed, so finished goods can be received from it."
        >
            <div class="space-y-3">
                <p v-if="fgPosition.remaining_receivable > 0" class="text-sm text-ink-700">
                    <strong>{{ pcs(fgPosition.remaining_receivable) }}</strong> of this job's output
                    is still unreceived. Reopening puts the card back to <em>completed</em>, where a
                    finished-goods receipt can be posted. Close it again afterwards.
                </p>
                <p v-else class="text-sm text-ink-700">
                    This card's output is already in stock, so reopening changes nothing about
                    inventory. It only returns the card to <em>completed</em>.
                </p>

                <FormField
                    label="Why is this card being reopened?"
                    rule="P0-3"
                    required
                    hint="Recorded on the card's history. Closing is normally final."
                    :error="reopenForm.errors.reopen_reason"
                >
                    <textarea v-model="reopenForm.reopen_reason" rows="2" class="form-textarea" />
                </FormField>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="reopenForm.processing"
                    :disabled="!reopenForm.reopen_reason"
                    @click="reopen"
                >Reopen</Button>
            </template>
        </Modal>

        <Modal
            v-model:open="cancelOpen"
            title="Cancel a job card with production against it"
            subtitle="J6: something has already been booked, so the cancellation is signed for."
        >
            <div class="space-y-3">
                <p class="text-sm text-ink-700">
                    Cancelling releases this card's stock reservations and returns issued material.
                    Booked production and its waste stay on the record — they happened.
                </p>

                <FormField
                    label="Supervisor reason"
                    rule="J6"
                    required
                    :error="cancelForm.errors.reason"
                >
                    <textarea v-model="cancelForm.reason" rows="2" class="form-textarea" />
                </FormField>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Keep the card</Button>
                <Button
                    variant="danger"
                    :loading="cancelForm.processing"
                    :disabled="!cancelForm.reason"
                    @click="cancelWithReason"
                >Cancel the card</Button>
            </template>
        </Modal>

        <Modal v-model:open="holdOpen" title="Hold this job card" subtitle="Holding frees the machine slot on the planning board.">
            <FormField label="Hold reason" :error="holdForm.errors.hold_reason" required>
                <textarea v-model="holdForm.hold_reason" rows="3" class="form-textarea" />
            </FormField>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="danger" :loading="holdForm.processing" :disabled="!holdForm.hold_reason" @click="hold">
                    Hold
                </Button>
            </template>
        </Modal>

        <Modal
            v-model:open="bookOpen"
            title="Book output manually"
            subtitle="For when the terminal could not take it. The same J3 and J5 limits apply, and this booking is marked as keyed at a desk."
            width="max-w-2xl"
        >
            <div v-if="nothingBookable" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                {{ nothingBookableReason }}
            </div>

            <div v-else class="space-y-3">
                <FormField label="Operation" :error="bookForm.errors.job_card_operation_id" required>
                    <SelectInput v-model="bookForm.job_card_operation_id" :options="bookableOperations" hint-key="hint" />
                </FormField>

                <div class="grid grid-cols-3 gap-3">
                    <FormField label="Input received" :error="bookForm.errors.input_qty">
                        <TextInput v-model="bookForm.input_qty" inputmode="decimal" />
                    </FormField>
                    <FormField label="Good" :error="bookForm.errors.good_qty" required>
                        <TextInput v-model="bookForm.good_qty" inputmode="decimal" />
                    </FormField>
                    <FormField label="Waste" :error="bookForm.errors.waste_qty">
                        <TextInput v-model="bookForm.waste_qty" inputmode="decimal" />
                    </FormField>
                </div>

                <!-- G4 — waste with a cause, asked only when there is waste to explain. -->
                <FormField v-if="Number(bookForm.waste_qty) > 0" label="What was the waste?" :error="bookForm.errors.waste_type" required>
                    <SelectInput v-model="bookForm.waste_type" :options="WASTE_TYPES" />
                </FormField>

                <div class="grid grid-cols-3 gap-3">
                    <FormField
                        label="Operator"
                        hint="Whoever ran it, not whoever is typing. From Configuration → Lists → Employees."
                        :error="bookForm.errors.operator_id"
                        required
                    >
                        <SelectInput v-model="bookForm.operator_id" :options="operatorOptions" hint-key="hint" />
                    </FormField>
                    <FormField label="Machine" :error="bookForm.errors.machine_id">
                        <SelectInput
                            v-model="bookForm.machine_id"
                            :options="machines.map((m) => ({ value: m.id, label: m.code, hint: m.name }))"
                            hint-key="hint"
                        />
                    </FormField>
                    <FormField label="Shift" :error="bookForm.errors.shift_id">
                        <SelectInput
                            v-model="bookForm.shift_id"
                            :options="shifts.map((sh) => ({ value: sh.id, label: sh.name }))"
                        />
                    </FormField>
                </div>

                <FormField
                    label="When was it made?"
                    hint="The shift this output belongs to, not the moment you are typing it. Utilisation is measured from this."
                    :error="bookForm.errors.occurred_at"
                    required
                >
                    <input v-model="bookForm.occurred_at" type="datetime-local" class="form-input">
                </FormField>

                <FormField
                    label="Why is this being keyed here?"
                    hint="Kept on the row. A booking that did not come off the machine has to say so."
                    :error="bookForm.errors.manual_reason"
                    required
                >
                    <TextInput v-model="bookForm.manual_reason" placeholder="Terminal at loom 3 would not start; figures taken from the shift sheet" />
                </FormField>

                <FormField
                    v-if="bookForm.errors.input_override_reason || bookForm.input_override_reason"
                    label="Why more than planned?"
                    :error="bookForm.errors.input_override_reason"
                >
                    <TextInput v-model="bookForm.input_override_reason" />
                </FormField>

                <FormField label="Remarks" :error="bookForm.errors.remarks">
                    <TextInput v-model="bookForm.remarks" />
                </FormField>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="bookForm.processing"
                    :disabled="!bookForm.job_card_operation_id || !bookForm.operator_id || (bookForm.manual_reason ?? '').length < 5"
                    @click="submitBooking"
                >
                    Book output
                </Button>
            </template>
        </Modal>

        <!--
            I1 — the original booking is never edited. It is what the operator recorded, and
            rewriting it would destroy the evidence that the mistake happened; this books a
            second row with negated quantities pointing back at it.
        -->
        <Modal
            :open="reversing !== null"
            title="Reverse this booking"
            subtitle="The original stays on the record. A reversing entry cancels it, and the totals move back."
            @update:open="reversing = null"
        >
            <div v-if="reversing" class="space-y-3">
                <dl class="grid grid-cols-3 gap-2 rounded-md bg-slate-50 px-3 py-2 text-sm">
                    <div>
                        <dt class="text-xs text-ink-500">Operation</dt>
                        <dd class="font-medium">{{ reversing.sequence_no }} · {{ reversing.operation }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Good</dt>
                        <dd class="font-medium tnum text-emerald-700">{{ qty(reversing.good_qty) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Waste</dt>
                        <dd class="font-medium tnum text-rose-600">{{ qty(reversing.waste_qty) }}</dd>
                    </div>
                </dl>

                <FormField
                    label="Why is this being reversed?"
                    hint="Kept on the audit trail. A correction with no stated reason cannot be told apart from tampering."
                    :error="reversalForm.errors.reason"
                    required
                >
                    <textarea v-model="reversalForm.reason" rows="3" class="form-textarea" placeholder="Operator keyed 5,000 instead of 500" />
                </FormField>

                <p v-if="reversalForm.errors.operation_log_id" class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    {{ reversalForm.errors.operation_log_id }}
                </p>
            </div>

            <template #footer>
                <Button @click="reversing = null">Cancel</Button>
                <Button
                    variant="danger"
                    :loading="reversalForm.processing"
                    :disabled="(reversalForm.reason ?? '').length < 5"
                    @click="submitReversal"
                >
                    Reverse booking
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
