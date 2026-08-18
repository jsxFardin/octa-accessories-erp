<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    vehicles: { type: Array, default: () => [] },
    drivers: { type: Array, default: () => [] },
    challans: { type: Array, default: () => [] },
});

const form = useForm({
    vehicle_id: null,
    driver_id: null,
    trip_date: new Date().toISOString().slice(0, 10),
    route_zone: '',
    start_odometer: null,
    remarks: '',
    stops: [],
});

const vehicleOptions = computed(() => props.vehicles.map((v) => ({ value: v.id, label: `${v.registration_no} (${v.kind})` })));
const driverOptions = computed(() => props.drivers.map((d) => ({ value: d.id, label: d.name })));

function addStop(challanId) {
    if (form.stops.find((s) => s.delivery_challan_id === challanId)) return;
    form.stops.push({ delivery_challan_id: challanId });
}

function removeStop(index) {
    form.stops.splice(index, 1);
}

const availableChallans = computed(() => props.challans.filter((c) => !form.stops.find((s) => s.delivery_challan_id === c.id)));

function submit() {
    form.post('/trips', { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head title="Plan a trip" />

        <template #title>Plan a trip</template>
        <template #subtitle>DF-4 — select vehicle, driver and sequence the drops</template>

        <FormLayout @submit="submit">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Vehicle" :error="form.errors.vehicle_id" required>
                        <SelectInput v-model="form.vehicle_id" :options="vehicleOptions" placeholder="Choose…" />
                    </FormField>
                    <FormField label="Driver" :error="form.errors.driver_id">
                        <SelectInput v-model="form.driver_id" :options="driverOptions" placeholder="Optional…" clearable />
                    </FormField>
                    <FormField label="Trip date" :error="form.errors.trip_date" required>
                        <DateInput v-model="form.trip_date" />
                    </FormField>
                    <FormField label="Route zone" :error="form.errors.route_zone">
                        <TextInput v-model="form.route_zone" placeholder="e.g. Gazipur" />
                    </FormField>
                </div>

                <Card title="Stops" :padded="false" class="mt-4">
                    <div class="p-3" v-if="availableChallans.length">
                        <p class="text-xs text-ink-500 mb-2">Click a challan to add as a stop:</p>
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="challan in availableChallans"
                                :key="challan.id"
                                type="button"
                                class="rounded bg-slate-100 px-2 py-1 text-xs hover:bg-brand-100"
                                @click="addStop(challan.id)"
                            >
                                {{ challan.number }} — {{ challan.customer }}
                            </button>
                        </div>
                    </div>
                    <ul v-if="form.stops.length" class="divide-y divide-slate-100">
                        <li v-for="(stop, index) in form.stops" :key="index" class="flex items-center justify-between px-4 py-2 text-sm">
                            <span class="font-medium">{{ index + 1 }}.</span>
                            <span>{{ challans.find((c) => c.id === stop.delivery_challan_id)?.number }}</span>
                            <span class="text-ink-500">{{ challans.find((c) => c.id === stop.delivery_challan_id)?.customer }}</span>
                            <button type="button" class="text-ink-400 hover:text-rose-600" @click="removeStop(index)">×</button>
                        </li>
                    </ul>
                    <p v-else class="p-4 text-center text-sm text-ink-500">No stops added yet.</p>
                </Card>

            <template #footer>
                <FormFooter
                    :form="form"
                    :disabled="!form.stops.length"
                    cancel-href="/trips"
                    label="Plan trip"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
