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

const props = defineProps({ tools: Object, filters: Object });

const columns = [
    { key: 'code', label: 'Code', sort: true },
    { key: 'kind', label: 'Kind', sort: true },
    { key: 'colour_index', label: 'Colour', align: 'center' },
    { key: 'life_impressions', label: 'Life', align: 'right' },
    { key: 'used_impressions', label: 'Used', align: 'right' },
    { key: 'remaining', label: 'Remaining', align: 'right' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Tools" />

        <template #title>Tools</template>
        <template #subtitle>Plates, screens, dies — with the impressions they have left (BR-13)</template>

        <template #actions>
            <span />
        </template>

        <Card :padded="false">
            <FilterBar :filters="filters" :fields="[{ key: 'kind', label: 'Kind', options: ['flexo_plate','screen','die','offset_plate','cad_pattern'].map((s) => ({ value: s, label: titleCase(s) })) }, { key: 'status', label: 'Status', options: ['available','in_use','worn','retired','lost'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search tool code…" />

            <DataTable
                :columns="columns"
                :rows="tools"
                row-key="id"
                empty="No tools registered."
            >
                <template #cell:code="{ row, value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:kind="{ row, value }">{{ titleCase(value) }}</template>
                <template #cell:life_impressions="{ row, value }">{{ pcs(value) }}</template>
                <template #cell:used_impressions="{ row, value }">{{ pcs(value) }}</template>
                <template #cell:remaining="{ row, value }"><span :class="Number(row.life_impressions) - Number(row.used_impressions) <= 0 ? 'text-rose-600 font-medium' : ''">
                        {{ pcs(Math.max(0, Number(row.life_impressions) - Number(row.used_impressions))) }}
                    </span></template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="tool"
                        title="No tools yet"
                        description="Dies, screens and cylinders — their reuse is what keeps a repeat order cheaper than the first."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
