<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import { datetime, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    users: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
});

const columns = [
    { key: 'created_at', label: 'When' },
    { key: 'user', label: 'Who' },
    { key: 'event', label: 'Event' },
    { key: 'record', label: 'Record' },
    { key: 'change', label: 'What changed', wrap: true },
    { key: 'ip_address', label: 'IP' },
];

const EVENTS = ['created', 'updated', 'deleted', 'restored', 'status_changed', 'printed', 'exported'];

const filterFields = [
    { key: 'event', label: 'Event', options: EVENTS.map((event) => ({ value: event, label: titleCase(event) })) },
    { key: 'type', label: 'Record', options: props.types },
    { key: 'user', label: 'Who', options: props.users.map((user) => ({ value: user.id, label: user.name })) },
    { key: 'from', label: 'From', type: 'date' },
    { key: 'to', label: 'To', type: 'date' },
];

/** Something a person can read for a value: never `[object Object]`, never a wall of JSON. */
function show(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'yes' : 'no';
    if (typeof value === 'object') return JSON.stringify(value).slice(0, 60);

    return String(value).length > 60 ? `${String(value).slice(0, 57)}…` : String(value);
}

/**
 * The fields that changed, old → new. A creation lists what was set; a status change leads
 * with the status and keeps its reason.
 */
function changes(row) {
    const next = row.new_values ?? {};
    const previous = row.old_values ?? {};
    const keys = Object.keys(next).filter((key) => key !== 'updated_at' && show(previous[key]) !== show(next[key]));

    return keys.slice(0, 5).map((key) => ({ key, label: titleCase(key), from: previous[key], to: next[key], hasFrom: key in previous }));
}
</script>

<template>
    <AppLayout>
        <Head title="Audit log" />

        <template #title>Audit log</template>
        <template #subtitle>Who changed what, and when. Every change records the person who made it.</template>

        <Card :padded="false">
            <FilterBar :only="['entries', 'filters']" :filters="filters" :fields="filterFields" placeholder="Search by record type, action or record number…" />

            <DataTable :only="['entries', 'filters']" :columns="columns" :rows="entries" row-key="id" empty="Nothing logged yet." dense>
                <template #cell:created_at="{ value }">{{ datetime(value) }}</template>
                <template #cell:user="{ value }">{{ value ?? 'System' }}</template>
                <template #cell:event="{ value }"><Badge :status="value === 'status_changed' ? 'info' : 'neutral'" :label="titleCase(value)" /></template>
                <template #cell:record="{ row }">
                    <Link v-if="row.href" :href="row.href" class="doc-link-quiet">{{ titleCase(row.record) }} #{{ row.auditable_id }}</Link>
                    <span v-else>{{ titleCase(row.record) }} #{{ row.auditable_id }}</span>
                </template>
                <template #cell:change="{ row }">
                    <ul class="space-y-0.5 text-xs">
                        <li v-for="change in changes(row)" :key="change.key" class="flex flex-wrap gap-x-1.5">
                            <span class="text-ink-500">{{ change.label }}</span>
                            <template v-if="change.hasFrom">
                                <span class="text-ink-600">{{ show(change.from) }}</span>
                                <span class="text-ink-400" aria-hidden="true">→</span>
                                <span class="sr-only">changed to</span>
                            </template>
                            <span class="font-medium text-ink-900">{{ show(change.to) }}</span>
                        </li>
                        <li v-if="changes(row).length === 0" class="text-ink-400">No field values recorded.</li>
                    </ul>
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
