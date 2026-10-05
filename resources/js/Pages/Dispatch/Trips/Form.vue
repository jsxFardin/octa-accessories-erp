<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, pcs, todayIso } from '@/plugins/formatting';

const props = defineProps({
    vehicles: { type: Array, default: () => [] },
    drivers: { type: Array, default: () => [] },
    challans: { type: Array, default: () => [] },
    // Issued notes that are not offered here because they do not travel on our vehicles.
    otherModes: { type: Number, default: 0 },
});

const form = useForm({
    vehicle_id: null,
    driver_id: null,
    trip_date: todayIso(),
    route_zone: '',
    start_odometer: null,
    remarks: '',
    stops: [],
});

const vehicleOptions = computed(() => props.vehicles.map((v) => ({
    value: v.id,
    label: v.registration_no,
    hint: [v.kind, v.capacity_kg ? `${pcs(v.capacity_kg)} kg` : null].filter(Boolean).join(' · '),
})));
const driverOptions = computed(() => props.drivers.map((d) => ({ value: d.id, label: d.name })));

const byId = computed(() => new Map(props.challans.map((challan) => [challan.id, challan])));

/** The trip's stops, in order, each with the note it delivers. */
const stops = computed(() => form.stops.map((stop) => byId.value.get(stop.delivery_challan_id)).filter(Boolean));

const search = ref('');
const zone = ref(null);

const zones = computed(() => [...new Set(props.challans.map((challan) => challan.route_zone).filter(Boolean))].sort());
const zoneOptions = computed(() => zones.value.map((name) => ({ value: name, label: name })));

/** Notes still to be placed, narrowed by zone and by anything typed. */
const waiting = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.challans.filter((challan) => {
        if (form.stops.some((stop) => stop.delivery_challan_id === challan.id)) return false;
        if (zone.value && challan.route_zone !== zone.value) return false;
        if (!term) return true;

        return [challan.number, challan.customer, challan.address, challan.route_zone]
            .some((text) => String(text ?? '').toLowerCase().includes(term));
    });
});

const unplaced = computed(() => props.challans.length - form.stops.length);

function addStop(challanId) {
    if (form.stops.find((s) => s.delivery_challan_id === challanId)) return;
    form.stops.push({ delivery_challan_id: challanId });

    // A trip to one zone is the ordinary case: the first stop names it, and it can be retyped.
    if (!form.route_zone) form.route_zone = byId.value.get(challanId)?.route_zone ?? '';
}

function addAllShown() {
    waiting.value.map((challan) => challan.id).forEach(addStop);
}

function removeStop(index) {
    form.stops.splice(index, 1);
}

function move(index, by) {
    const to = index + by;

    if (to < 0 || to >= form.stops.length) return;

    const [stop] = form.stops.splice(index, 1);
    form.stops.splice(to, 0, stop);
}

const totals = computed(() => ({
    cartons: stops.value.reduce((sum, challan) => sum + challan.cartons, 0),
    qty: stops.value.reduce((sum, challan) => sum + challan.qty, 0),
}));

// Arriving from "Plan a trip" on a delivery note puts that note on the trip to begin with.
onMounted(() => {
    const id = Number(new URLSearchParams(window.location.search).get('challan'));

    if (id && byId.value.has(id)) addStop(id);
});

const blockedBy = computed(() => {
    if (!form.vehicle_id) return 'Choose a vehicle.';
    if (!form.stops.length) return 'Add at least one delivery note to the trip.';

    return null;
});

function submit() {
    form.post('/trips', { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head title="Plan a trip" />

        <template #title>Plan a trip</template>
        <template #subtitle>Choose the vehicle and driver, then put the delivery notes in the order they will be dropped.</template>

        <FormLayout @submit="submit">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
                <FormField label="Vehicle" :error="form.errors.vehicle_id" required>
                    <SelectInput v-model="form.vehicle_id" :options="vehicleOptions" placeholder="Choose…" />
                </FormField>
                <FormField label="Driver" :error="form.errors.driver_id">
                    <SelectInput v-model="form.driver_id" :options="driverOptions" placeholder="Optional…" clearable />
                </FormField>
                <FormField label="Trip date" :error="form.errors.trip_date" required>
                    <DateInput v-model="form.trip_date" />
                </FormField>
                <FormField label="Route zone" :error="form.errors.route_zone" hint="Filled from the first stop.">
                    <TextInput v-model="form.route_zone" placeholder="e.g. Gazipur" />
                </FormField>
                <FormField
                    label="Start odometer (km)"
                    :error="form.errors.start_odometer"
                    hint="If known now. It is asked again when the trip starts."
                >
                    <TextInput v-model="form.start_odometer" type="number" min="0" step="any" numeric placeholder="0" />
                </FormField>
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <Card title="Delivery notes waiting" :padded="false">
                    <template #actions>
                        <Button v-if="waiting.length > 1" type="button" size="sm" @click="addAllShown">
                            Add all {{ waiting.length }}
                        </Button>
                    </template>

                    <div v-if="challans.length" class="flex flex-wrap items-end gap-3 border-b border-slate-200 px-4 py-3">
                        <FormField label="Search" class="min-w-40 flex-1">
                            <TextInput v-model="search" placeholder="Number, customer or address" />
                        </FormField>
                        <FormField v-if="zones.length > 1" label="Zone" class="w-44">
                            <SelectInput v-model="zone" :options="zoneOptions" placeholder="All zones" clearable />
                        </FormField>
                    </div>

                    <ul v-if="waiting.length" class="divide-y divide-slate-100" data-waiting>
                        <li v-for="challan in waiting" :key="challan.id" class="flex items-start gap-3 px-4 py-3 text-sm">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink-900">{{ challan.customer ?? 'No customer' }}</p>
                                <p class="text-ink-700">{{ challan.address ?? 'No delivery address on this note' }}</p>
                                <p class="mt-0.5 text-xs text-ink-500">
                                    {{ challan.number }} · {{ date(challan.challan_date) }} ·
                                    {{ pcs(challan.cartons) }} {{ challan.cartons === 1 ? 'carton' : 'cartons' }} ·
                                    {{ pcs(challan.qty) }} pcs
                                </p>
                            </div>
                            <span v-if="challan.route_zone" class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-ink-700">
                                {{ challan.route_zone }}
                            </span>
                            <Button type="button" size="sm" class="shrink-0" :aria-label="`Add ${challan.number} to the trip`" @click="addStop(challan.id)">
                                Add
                            </Button>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-6 text-center text-sm text-ink-500">
                        <template v-if="!challans.length">
                            No delivery notes are waiting for a trip. A note appears here once it is issued for delivery by own vehicle.
                        </template>
                        <template v-else-if="unplaced === 0">Every waiting delivery note is on this trip.</template>
                        <template v-else>
                            Nothing matches.
                            <button type="button" class="text-brand-700 underline" @click="search = ''; zone = null">Clear the search</button>
                        </template>
                    </p>

                    <p v-if="otherModes" class="border-t border-slate-200 px-4 py-2 text-xs text-ink-500">
                        {{ otherModes }} more issued {{ otherModes === 1 ? 'note goes' : 'notes go' }} by courier, freight forwarder
                        or customer pickup, and {{ otherModes === 1 ? 'is' : 'are' }} not planned on a trip.
                    </p>
                </Card>

                <Card title="Stops, in drop order" :padded="false">
                    <ol v-if="stops.length" class="divide-y divide-slate-100" data-stops>
                        <li v-for="(challan, index) in stops" :key="challan.id" class="flex items-start gap-3 px-4 py-3 text-sm">
                            <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white tnum">
                                {{ index + 1 }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink-900">{{ challan.customer ?? 'No customer' }}</p>
                                <p class="text-ink-700">{{ challan.address ?? 'No delivery address on this note' }}</p>
                                <p class="mt-0.5 text-xs text-ink-500">
                                    {{ challan.number }}<span v-if="challan.route_zone"> · {{ challan.route_zone }}</span> ·
                                    {{ pcs(challan.cartons) }} {{ challan.cartons === 1 ? 'carton' : 'cartons' }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <Button
                                    type="button" size="sm" :disabled="index === 0"
                                    :aria-label="`Move ${challan.number} earlier`" @click="move(index, -1)"
                                >↑</Button>
                                <Button
                                    type="button" size="sm" :disabled="index === stops.length - 1"
                                    :aria-label="`Move ${challan.number} later`" @click="move(index, 1)"
                                >↓</Button>
                                <Button
                                    type="button" size="sm" variant="ghost"
                                    :aria-label="`Take ${challan.number} off the trip`" @click="removeStop(index)"
                                >Remove</Button>
                            </div>
                        </li>
                    </ol>
                    <p v-else class="px-4 py-6 text-center text-sm text-ink-500">
                        No stops yet. Add delivery notes from the list; the first one added is the first drop.
                    </p>

                    <p v-if="form.errors.stops" class="border-t border-slate-200 px-4 py-2 text-sm text-rose-700" role="alert">{{ form.errors.stops }}</p>

                    <p v-if="stops.length" class="border-t border-slate-200 px-4 py-2 text-xs text-ink-600">
                        {{ stops.length }} {{ stops.length === 1 ? 'stop' : 'stops' }} ·
                        <span class="tnum">{{ pcs(totals.cartons) }}</span> {{ totals.cartons === 1 ? 'carton' : 'cartons' }} ·
                        <span class="tnum">{{ pcs(totals.qty) }}</span> pcs
                    </p>
                </Card>
            </div>

            <FormField label="Note for the driver" :error="form.errors.remarks" class="mt-4 max-w-2xl">
                <TextInput v-model="form.remarks" placeholder="Gate closes at 5 pm, ask for the store manager…" />
            </FormField>

            <template #footer>
                <FormFooter
                    :form="form"
                    :disabled="blockedBy !== null"
                    :disabled-reason="blockedBy"
                    cancel-href="/trips"
                    label="Plan trip"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
