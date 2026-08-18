<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { date, money, pcs, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ trips: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'vehicle', label: 'Vehicle' },
    { key: 'driver', label: 'Driver' },
    { key: 'route_zone', label: 'Zone' },
    { key: 'trip_date', label: 'Date', sort: true },
    { key: 'stops', label: 'Stops', align: 'center' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Trips" />

        <template #title>Trips</template>
        <template #subtitle>The owned fleet: multi-drop routes with POD at each stop</template>

        <template #actions>
            <Button v-if="can('trip.create')" size="sm" variant="primary" :href="'/trips/create'">
                Plan trip
            </Button>
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['planned','loading','in_transit','completed','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search trip number…" />

            <DataTable
                :columns="columns"
                :rows="trips"
                row-key="id"
                :row-href="(row) => `/trips/${row.id}`"
                empty="No trips planned."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:trip_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="trip"
                        title="No trips yet"
                        description="A trip sequences drops for one vehicle and collects proof of delivery."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
