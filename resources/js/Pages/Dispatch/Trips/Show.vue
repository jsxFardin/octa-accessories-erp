<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
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

function openPod(stop) {
    podStop.value = stop;
    podForm.reset();
}

function submitPod() {
    podForm.post(`/trips/${props.trip.id}/stops/${podStop.value.id}/deliver`, {
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

        <Modal v-if="podStop" v-model:open="podStop" title="Capture POD" width="max-w-md" @update:open="(v) => { if (!v) podStop = null; }">
            <div class="flex flex-col gap-3">
                <FormField label="Received by" :error="podForm.errors.received_by_name" required>
                    <input v-model="podForm.received_by_name" type="text" class="w-full rounded-md border-slate-300 text-sm" placeholder="Receiver name" />
                </FormField>
                <FormField label="Failure reason (leave blank if delivered)" :error="podForm.errors.failure_reason">
                    <input v-model="podForm.failure_reason" type="text" class="w-full rounded-md border-slate-300 text-sm" placeholder="Optional — marks as failed" />
                </FormField>
            </div>
            <template #footer>
                <Button @click="podStop = null">Cancel</Button>
                <Button variant="primary" :disabled="podForm.processing" @click="submitPod">Confirm</Button>
            </template>
        </Modal>

        <Modal v-model:open="completeOpen" title="Complete trip" width="max-w-sm">
            <div class="flex flex-col gap-3">
                <FormField label="End odometer">
                    <input v-model="completeForm.end_odometer" type="number" min="0" step="any" class="w-full rounded-md border-slate-300 text-sm" />
                </FormField>
                <FormField label="Fuel cost">
                    <input v-model="completeForm.fuel_cost" type="number" min="0" step="any" class="w-full rounded-md border-slate-300 text-sm" />
                </FormField>
            </div>
            <template #footer>
                <Button @click="completeOpen = false">Cancel</Button>
                <Button variant="primary" :disabled="completeForm.processing" @click="submitComplete">Complete</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
