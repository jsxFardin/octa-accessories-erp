<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ artworks: { type: Object, required: true }, filters: { type: Object, default: () => ({}) } });

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'title', label: 'Title' },
    { key: 'product', label: 'Product' },
    { key: 'customer', label: 'Customer' },
    { key: 'version_count', label: 'Versions', align: 'center' },
    { key: 'latest_version', label: 'Latest' },
    { key: 'approved_version', label: 'Approved' },
];
</script>

<template>
    <AppLayout>
        <Head title="Artwork" />

        <template #title>Artwork</template>
        <template #subtitle>Gate 1 — production may only run against an approved version</template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[{ key: 'state', label: 'Gate', options: [
                    { value: 'awaiting_approval', label: 'Awaiting customer' },
                    { value: 'unapproved', label: 'No approved version' },
                ] }]"
                placeholder="Search artwork code or title…"
            />

            <DataTable :columns="columns" :rows="artworks" row-key="id" :row-href="(row) => `/artworks/${row.id}`"
                       empty="No artwork matches these filters.">
                <template #cell:code="{ value }"><span class="font-medium text-slate-900">{{ value }}</span></template>
                <template #cell:product="{ row }">
                    <span v-if="row.product"><span class="font-medium">{{ row.product.code }}</span> {{ row.product.name }}</span>
                </template>
                <template #cell:latest_version="{ value }">
                    <span v-if="value" class="flex items-center gap-1">
                        <span class="tnum text-xs">v{{ value.version_no }}</span>
                        <Badge :status="value.status" />
                    </span>
                </template>
                <template #cell:approved_version="{ value }">
                    <Badge v-if="value" tone="success" :label="`v${value.version_no}`" />
                    <!-- No approved version is the blocking condition, so it reads as one -->
                    <Badge v-else tone="danger" label="Blocked" />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
