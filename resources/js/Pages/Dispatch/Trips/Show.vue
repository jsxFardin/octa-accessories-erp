<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { baseCurrency, date, datetime, pcs, time } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    trip: { type: Object, required: true },
    stops: { type: Array, default: () => [] },
});

/*
 * Starting is asked about: it puts every delivery note on the trip in transit, and it is the
 * one moment the odometer's start reading is known. It used to be a bare click that recorded
 * neither a reading nor a second thought.
 */
const startForm = useForm({ start_odometer: props.trip.start_odometer });
const startOpen = ref(false);

function submitStart() {
    startForm.post(`/trips/${props.trip.id}/start`, {
        preserveScroll: true,
        onSuccess: () => { startOpen.value = false; },
    });
}

const completeForm = useForm({ end_odometer: null, fuel_cost: null });
const completeOpen = ref(false);

function submitComplete() {
    completeForm.post(`/trips/${props.trip.id}/complete`, {
        preserveScroll: true,
        onSuccess: () => { completeOpen.value = false; },
    });
}

const done = computed(() => props.stops.filter((stop) => ['delivered', 'failed'].includes(stop.status)).length);
const failed = computed(() => props.stops.filter((stop) => stop.status === 'failed').length);
const open = (stop) => ['pending', 'arrived'].includes(stop.status);

/** The stop the driver is going to next: the first one still open. */
const nextStopId = computed(() => (props.trip.status === 'in_transit' ? props.stops.find(open)?.id ?? null : null));

const distance = computed(() => {
    const from = Number(props.trip.start_odometer);
    const to = Number(props.trip.end_odometer);

    return props.trip.start_odometer !== null && props.trip.end_odometer !== null && to >= from ? to - from : null;
});

const completeBlockedBy = computed(() => (done.value < props.stops.length
    ? `${props.stops.length - done.value} ${props.stops.length - done.value === 1 ? 'stop is' : 'stops are'} still to be recorded.`
    : null));

/* The route can be put in a different order until the vehicle leaves. */
const canReorder = computed(() => props.trip.status === 'planned' && can('trip.create') && props.stops.length > 1);
const reordering = ref(false);

function move(index, by) {
    const to = index + by;

    if (reordering.value || to < 0 || to >= props.stops.length) return;

    const order = props.stops.map((stop) => stop.id);
    order.splice(to, 0, order.splice(index, 1)[0]);

    reordering.value = true;
    router.post(`/trips/${props.trip.id}/stops/order`, { stops: order }, {
        preserveScroll: true,
        onFinish: () => { reordering.value = false; },
    });
}

const podStop = ref(null);
const podForm = useForm({ received_by_name: '', failure_reason: '' });

/*
 * One dialog, two outcomes, chosen by the button that opened it. Both fields used to sit open
 * at once and the failure reason quietly decided which one the system believed — while the
 * server still demanded a receiver's name for a drop nobody received.
 */
const podOutcome = ref('delivered');
const podFailing = computed(() => podOutcome.value === 'failed');

function openPod(stop, outcome = 'delivered') {
    podStop.value = stop;
    podOutcome.value = outcome;
    podForm.reset();
    podForm.clearErrors();
}

const podBlockedBy = computed(() => {
    if (podFailing.value) {
        return podForm.failure_reason.trim() ? null : 'Say why the goods could not be delivered.';
    }

    return podForm.received_by_name.trim() ? null : 'Enter the name of the person who received the goods.';
});

function submitPod() {
    podForm
        .transform((data) => (podFailing.value
            ? { failure_reason: data.failure_reason }
            : { received_by_name: data.received_by_name }))
        .post(`/trips/${props.trip.id}/stops/${podStop.value.id}/deliver`, {
            preserveScroll: true,
            onSuccess: () => { podStop.value = null; },
        });
}

/** A phone number as a link the handset will dial. */
const dial = (phone) => `tel:${String(phone).replace(/[^\d+]/g, '')}`;
</script>

<template>
    <AppLayout>
        <Head :title="trip.number ?? 'Trip'" />

        <template #title>{{ trip.number ?? '(trip)' }}</template>
        <template #subtitle>{{ date(trip.trip_date) }} · {{ trip.route_zone || 'No zone' }} · {{ trip.vehicle }}</template>

        <template #actions>
            <Badge :status="trip.status" />
            <Button v-if="trip.status === 'planned' && can('trip.start')" size="sm" variant="primary" @click="startOpen = true">
                Start trip
            </Button>
            <Button v-if="trip.status === 'in_transit' && can('trip.complete')" size="sm" variant="primary" @click="completeOpen = true">
                Complete trip
            </Button>
        </template>

        <div class="space-y-4">
            <Card title="Details">
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs text-ink-500">Driver</dt><dd>{{ trip.driver ?? 'Not assigned' }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Vehicle</dt><dd>{{ trip.vehicle }}</dd></div>
                    <div>
                        <dt class="text-xs text-ink-500">Stops recorded</dt>
                        <dd class="tnum">
                            {{ done }} of {{ stops.length }}<span v-if="failed" class="text-rose-700"> · {{ failed }} not delivered</span>
                        </dd>
                    </div>
                    <div v-if="trip.started_at"><dt class="text-xs text-ink-500">Started</dt><dd>{{ datetime(trip.started_at) }}</dd></div>
                    <div v-if="trip.completed_at"><dt class="text-xs text-ink-500">Completed</dt><dd>{{ datetime(trip.completed_at) }}</dd></div>
                    <div v-if="trip.start_odometer !== null"><dt class="text-xs text-ink-500">Start odometer</dt><dd class="tnum">{{ pcs(trip.start_odometer) }} km</dd></div>
                    <div v-if="trip.end_odometer !== null"><dt class="text-xs text-ink-500">End odometer</dt><dd class="tnum">{{ pcs(trip.end_odometer) }} km</dd></div>
                    <div v-if="distance !== null"><dt class="text-xs text-ink-500">Distance</dt><dd class="tnum">{{ pcs(distance) }} km</dd></div>
                </dl>
                <p v-if="trip.remarks" class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    <span class="font-medium">Note for the driver:</span> {{ trip.remarks }}
                </p>
            </Card>

            <!--
                The driver's screen. Each stop is a card rather than a table row, because it is
                read on a phone in a cab: where to go, who to ring, what to hand over, and two
                buttons a thumb can hit. A row used to carry the customer's name and nothing
                a driver could act on.
            -->
            <section aria-labelledby="stops-title">
                <h2 id="stops-title" class="mb-2 text-sm font-semibold text-ink-900">Stops, in drop order</h2>

                <p v-if="trip.status === 'planned'" class="mb-2 text-sm text-ink-600">
                    Deliveries can be recorded once the trip has started.
                    <span v-if="canReorder">Use the arrows to change the order.</span>
                </p>

                <ol class="space-y-3" data-stops>
                    <li
                        v-for="(stop, index) in stops"
                        :key="stop.id"
                        class="rounded-lg border bg-white p-4"
                        :class="stop.id === nextStopId ? 'border-brand-600 ring-1 ring-brand-600' : 'border-slate-200'"
                        data-stop
                    >
                        <div class="flex flex-wrap items-start gap-3">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold tnum"
                                :class="open(stop) ? 'bg-brand-600 text-white' : 'bg-slate-200 text-ink-700'"
                            >{{ stop.sequence_no }}</span>

                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="text-base font-semibold text-ink-900">{{ stop.customer ?? 'No customer' }}</span>
                                    <Badge :status="stop.status" />
                                    <span v-if="stop.id === nextStopId" class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700">Next stop</span>
                                </p>
                                <p class="mt-1 text-sm text-ink-700">
                                    {{ stop.address ?? 'No delivery address on the delivery note' }}
                                    <span v-if="stop.route_zone" class="text-ink-500"> · {{ stop.route_zone }}</span>
                                </p>
                                <p v-if="stop.phone" class="mt-1 text-sm">
                                    <a :href="dial(stop.phone)" class="inline-flex min-h-6 items-center font-medium text-brand-700 underline">
                                        Call {{ stop.contact_name ?? 'the customer' }}: {{ stop.phone }}
                                    </a>
                                </p>
                                <p class="mt-1 text-sm text-ink-600">
                                    <!-- A link only for someone who may open it: a driver holds no permission on delivery notes. -->
                                    <Link v-if="stop.challan_id && can('delivery_challan.view')" :href="`/delivery-challans/${stop.challan_id}`" class="doc-link">{{ stop.challan_number ?? 'Delivery note' }}</Link>
                                    <span v-else class="font-medium text-ink-900">{{ stop.challan_number ?? 'Delivery note' }}</span>
                                    · <span class="tnum">{{ pcs(stop.cartons) }}</span> {{ stop.cartons === 1 ? 'carton' : 'cartons' }}
                                    · <span class="tnum">{{ pcs(stop.qty) }}</span> pcs
                                </p>
                                <p v-if="stop.status === 'delivered'" class="mt-1 text-sm text-emerald-800">
                                    Received by {{ stop.received_by_name }}<span v-if="stop.pod_captured_at"> at {{ time(stop.pod_captured_at) }}</span>
                                </p>
                                <p v-if="stop.status === 'failed'" class="mt-1 text-sm text-rose-700">
                                    Not delivered: {{ stop.failure_reason }}
                                </p>
                            </div>

                            <div v-if="canReorder" class="flex shrink-0 gap-1">
                                <Button size="sm" :disabled="index === 0 || reordering" :aria-label="`Move stop ${stop.sequence_no} earlier`" @click="move(index, -1)">↑</Button>
                                <Button size="sm" :disabled="index === stops.length - 1 || reordering" :aria-label="`Move stop ${stop.sequence_no} later`" @click="move(index, 1)">↓</Button>
                            </div>
                        </div>

                        <div
                            v-if="open(stop) && trip.status === 'in_transit' && can('trip_stop.update')"
                            class="mt-3 grid grid-cols-2 gap-2 sm:max-w-md"
                        >
                            <Button size="lg" variant="primary" class="min-h-11" data-deliver @click="openPod(stop, 'delivered')">Delivered</Button>
                            <Button size="lg" class="min-h-11" data-not-delivered @click="openPod(stop, 'failed')">Could not deliver</Button>
                        </div>
                    </li>
                    <li v-if="!stops.length" class="rounded-lg border border-slate-200 bg-white px-4 py-6 text-center text-sm text-ink-500">
                        This trip has no stops.
                    </li>
                </ol>
            </section>
        </div>

        <Modal
            v-if="podStop"
            v-model:open="podStop"
            :title="`Stop ${podStop.sequence_no}: ${podStop.customer ?? 'delivery'}`"
            width="max-w-md"
            :dirty="podForm.isDirty"
            @update:open="(v) => { if (!v) podStop = null; }"
        >
            <div class="flex flex-col gap-3">
                <div class="grid grid-cols-2 gap-2" role="group" aria-label="What happened at this stop">
                    <button
                        type="button"
                        class="min-h-11 rounded-md border px-3 py-2 text-sm font-medium transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                        :class="!podFailing ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-300 bg-white text-ink-700 hover:bg-slate-50'"
                        :aria-pressed="!podFailing"
                        @click="podOutcome = 'delivered'"
                    >Delivered</button>
                    <button
                        type="button"
                        class="min-h-11 rounded-md border px-3 py-2 text-sm font-medium transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                        :class="podFailing ? 'border-rose-600 bg-rose-50 text-rose-700' : 'border-slate-300 bg-white text-ink-700 hover:bg-slate-50'"
                        :aria-pressed="podFailing"
                        @click="podOutcome = 'failed'"
                    >Could not deliver</button>
                </div>

                <FormField
                    v-if="!podFailing"
                    label="Received by"
                    :error="podForm.errors.received_by_name"
                    required
                    hint="The name written on the gate copy."
                >
                    <TextInput v-model="podForm.received_by_name" placeholder="Receiver name" />
                </FormField>

                <template v-else>
                    <FormField
                        label="Why it could not be delivered"
                        :error="podForm.errors.failure_reason"
                        required
                    >
                        <TextInput v-model="podForm.failure_reason" placeholder="Refused at the gate, address closed…" />
                    </FormField>
                    <p class="rounded-md bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900">
                        The goods stay on the vehicle. Their delivery note is marked returned and the
                        quantity goes back into stock. This cannot be undone from this screen.
                    </p>
                </template>

                <p v-if="podBlockedBy" id="pod-blocked" class="text-xs text-ink-600">{{ podBlockedBy }}</p>
            </div>
            <template #footer>
                <Button @click="podStop = null">Cancel</Button>
                <Button
                    :variant="podFailing ? 'danger' : 'primary'"
                    :loading="podForm.processing"
                    :disabled="podForm.processing || podBlockedBy !== null"
                    :aria-describedby="podBlockedBy ? 'pod-blocked' : null"
                    @click="submitPod"
                >{{ podFailing ? 'Mark as not delivered' : 'Confirm delivery' }}</Button>
            </template>
        </Modal>

        <Modal v-model:open="startOpen" :title="`Start trip ${trip.number ?? ''}?`" width="max-w-sm" :dirty="startForm.isDirty">
            <div class="flex flex-col gap-3">
                <p class="text-sm text-ink-700">
                    The {{ stops.length }} delivery {{ stops.length === 1 ? 'note' : 'notes' }} on this trip will be marked
                    as in transit. Start it when the vehicle is loaded and leaving.
                </p>
                <FormField
                    label="Start odometer (km)"
                    :error="startForm.errors.start_odometer"
                    hint="The reading on the dashboard now. Leave blank if the vehicle has no working meter."
                >
                    <TextInput v-model="startForm.start_odometer" type="number" min="0" step="any" numeric placeholder="0" />
                </FormField>
            </div>
            <template #footer>
                <Button @click="startOpen = false">Cancel</Button>
                <Button variant="primary" :loading="startForm.processing" :disabled="startForm.processing" data-start-confirm @click="submitStart">Start trip</Button>
            </template>
        </Modal>

        <Modal v-model:open="completeOpen" title="Complete trip" width="max-w-sm" :dirty="completeForm.isDirty">
            <div class="flex flex-col gap-3">
                <p v-if="completeBlockedBy" id="complete-blocked" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {{ completeBlockedBy }} Record each one as delivered or not delivered first.
                </p>
                <FormField
                    label="End odometer (km)"
                    :error="completeForm.errors.end_odometer"
                    :hint="trip.start_odometer !== null
                        ? `Started at ${pcs(trip.start_odometer)} km.`
                        : 'Leave blank if the vehicle has no working meter.'"
                >
                    <TextInput v-model="completeForm.end_odometer" type="number" min="0" step="any" numeric placeholder="0" />
                </FormField>
                <FormField :label="`Fuel cost (${baseCurrency()})`" :error="completeForm.errors.fuel_cost">
                    <TextInput v-model="completeForm.fuel_cost" type="number" min="0" step="any" numeric placeholder="0.00" />
                </FormField>
            </div>
            <template #footer>
                <Button @click="completeOpen = false">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="completeForm.processing"
                    :disabled="completeForm.processing || completeBlockedBy !== null"
                    :aria-describedby="completeBlockedBy ? 'complete-blocked' : null"
                    @click="submitComplete"
                >Complete trip</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
