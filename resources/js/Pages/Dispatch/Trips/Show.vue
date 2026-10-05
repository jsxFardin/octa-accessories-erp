<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    trip: { type: Object, required: true },
    stops: { type: Array, default: () => [] },
});

function start() {
    router.post(`/trips/${props.trip.id}/start`, {}, { preserveScroll: true });
}

const completeForm = useForm({ end_odometer: null, fuel_cost: null });
const completeOpen = ref(false);

function submitComplete() {
    completeForm.post(`/trips/${props.trip.id}/complete`, {
        preserveScroll: true,
        onSuccess: () => { completeOpen.value = false; },
    });
}

const podStop = ref(null);
const podForm = useForm({ received_by_name: '', failure_reason: '' });

/*
 * One dialog, two outcomes, chosen explicitly. Both fields used to sit open at once and the
 * failure reason quietly decided which one the system believed — while the server still
 * demanded a receiver's name for a drop nobody received.
 */
const podOutcome = ref('delivered');
const podFailing = computed(() => podOutcome.value === 'failed');

function openPod(stop) {
    podStop.value = stop;
    podOutcome.value = 'delivered';
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
</script>

<template>
    <AppLayout>
        <Head :title="trip.number ?? 'Trip'" />

        <template #title>{{ trip.number ?? '(trip)' }}</template>
        <template #subtitle>{{ date(trip.trip_date) }} · {{ trip.route_zone || 'No zone' }} · {{ trip.vehicle }}</template>

        <template #actions>
            <Badge :status="trip.status" />
            <Button v-if="trip.status === 'planned' && can('trip.start')" size="sm" variant="primary" @click="start">
                Start trip
            </Button>
            <Button v-if="trip.status === 'in_transit' && can('trip.complete')" size="sm" variant="primary" @click="completeOpen = true">
                Complete trip
            </Button>
        </template>

        <div class="space-y-4">
            <Card title="Details">
                <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs text-ink-500">Driver</dt><dd>{{ trip.driver ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Vehicle</dt><dd>{{ trip.vehicle }}</dd></div>
                    <div v-if="trip.started_at"><dt class="text-xs text-ink-500">Started</dt><dd>{{ date(trip.started_at) }}</dd></div>
                    <div v-if="trip.completed_at"><dt class="text-xs text-ink-500">Completed</dt><dd>{{ date(trip.completed_at) }}</dd></div>
                    <div v-if="trip.start_odometer"><dt class="text-xs text-ink-500">Start odo</dt><dd class="tnum">{{ trip.start_odometer }}</dd></div>
                    <div v-if="trip.end_odometer"><dt class="text-xs text-ink-500">End odo</dt><dd class="tnum">{{ trip.end_odometer }}</dd></div>
                </dl>
            </Card>

            <Card title="Stops" rule="DF-5 — deliver with POD" :padded="false">
                <ul class="divide-y divide-slate-100">
                    <li v-for="stop in stops" :key="stop.id" class="flex items-center gap-4 px-4 py-3 text-sm">
                        <span class="w-6 text-center font-medium text-ink-400">{{ stop.sequence_no }}</span>
                        <div class="flex-1">
                            <div class="font-medium">{{ stop.customer ?? '—' }}</div>
                            <div class="text-xs text-ink-500">
                                <a v-if="stop.challan_id" :href="`/delivery-challans/${stop.challan_id}`" class="text-brand-700 hover:underline">{{ stop.challan_number }}</a>
                            </div>
                        </div>
                        <div class="text-xs text-ink-500">
                            <span v-if="stop.arrived_at">Arrived {{ date(stop.arrived_at) }}</span>
                            <span v-if="stop.received_by_name"> · {{ stop.received_by_name }}</span>
                        </div>
                        <Badge :status="stop.status" />
                        <Button
                            v-if="['pending', 'arrived'].includes(stop.status) && trip.status === 'in_transit' && can('trip_stop.update')"
                            size="xs"
                            @click="openPod(stop)"
                        >
                            Deliver
                        </Button>
                    </li>
                    <li v-if="!stops.length" class="px-4 py-6 text-center text-ink-500">No stops.</li>
                </ul>
            </Card>
        </div>

        <Modal v-if="podStop" v-model:open="podStop" title="Record this stop" width="max-w-md" @update:open="(v) => { if (!v) podStop = null; }">
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

        <Modal v-model:open="completeOpen" title="Complete trip" width="max-w-sm">
            <div class="flex flex-col gap-3">
                <FormField label="End odometer" hint="Feeds distance per trip; leave blank if the vehicle has no working meter.">
                    <TextInput v-model="completeForm.end_odometer" type="number" min="0" step="any" numeric placeholder="0" />
                </FormField>
                <FormField label="Fuel cost">
                    <TextInput v-model="completeForm.fuel_cost" type="number" min="0" step="any" numeric placeholder="0.00" />
                </FormField>
            </div>
            <template #footer>
                <Button @click="completeOpen = false">Cancel</Button>
                <Button variant="primary" :loading="completeForm.processing" :disabled="completeForm.processing" @click="submitComplete">Complete</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
