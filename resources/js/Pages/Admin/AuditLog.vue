<script setup>
import { Head } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { datetime, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ entries: { type: Object, required: true }, filters: { type: Object, default: () => ({}) } });

const columns = [
    { key: 'created_at', label: 'When' },
    { key: 'user', label: 'Who' },
    { key: 'event', label: 'Event' },
    { key: 'auditable', label: 'Record' },
    { key: 'change', label: 'Change' },
    { key: 'ip_address', label: 'IP' },
];
</script>

<template>
    <AppLayout>
        <Head title="Audit log" />

        <template #title>Audit log</template>
        <template #subtitle>Written by a model observer, not a trigger — a trigger cannot see the authenticated user</template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[{ key: 'event', label: 'Event', options: ['created','updated','deleted','restored','status_changed','printed','exported'].map((e) => ({ value: e, label: titleCase(e) })) }]"
                placeholder="Search model or event…"
            />

            <DataTable :columns="columns" :rows="entries" row-key="id" empty="Nothing logged yet." dense>
                <template #cell:created_at="{ value }">{{ datetime(value) }}</template>
                <template #cell:user="{ value }">{{ value ?? 'system' }}</template>
                <template #cell:event="{ value }"><Badge :status="value === 'status_changed' ? 'info' : 'neutral'" :label="titleCase(value)" /></template>
                <template #cell:auditable="{ row }">{{ row.auditable }} #{{ row.auditable_id }}</template>
                <template #cell:change="{ row }">
                    <span v-if="row.event === 'status_changed'" class="text-xs">
                        {{ row.old_values?.status }} → <span class="font-medium">{{ row.new_values?.status }}</span>
                        <span v-if="row.new_values?.reason || row.new_values?.hold_reason" class="text-ink-500">
                            · {{ row.new_values.reason ?? row.new_values.hold_reason }}
                        </span>
                    </span>
                    <span v-else class="font-mono text-[10px] text-ink-500">
                        {{ Object.keys(row.new_values ?? {}).slice(0, 4).join(', ') }}
                    </span>
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
