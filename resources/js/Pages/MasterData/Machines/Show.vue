<script setup>
import { Head } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, datetime, money, pcs, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ machine: Object, load: Array, downtime: Array });

</script>

<template>
    <AppLayout>
        <Head :title="machine.code" />

        <template #title>{{ machine.code }} · {{ machine.name }}</template>
        <template #subtitle>{{ machine.group?.name }} · {{ machine.factory_unit?.name }}</template>

        <template #actions>
            <Button v-if="can('machine.update')" size="sm" :href="`/machines/${machine.id}/edit`">Edit</Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card title="Capability and cost" rule="BR-16 · BR-18 · BR-27">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-500">Standard rate / hour</dt><dd class="tnum">{{ machine.std_rate_per_hour ? qty(machine.std_rate_per_hour, 0) : '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Cost / hour</dt><dd class="tnum">{{ money(machine.hourly_rate) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">kW rating</dt><dd class="tnum">{{ machine.kw_rating ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Efficiency</dt><dd class="tnum">{{ machine.efficiency_pct }}%</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Web width</dt><dd class="tnum">{{ machine.web_width_mm ?? '—' }} mm</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Max colours</dt><dd class="tnum">{{ machine.max_colours ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Status</dt><dd><Badge :status="machine.status" /></dd></div>
                </dl>
            </Card>

            <Card class="lg:col-span-2" title="Scheduled load" rule="BR-27" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'load_date', label: 'Date' },
                        { key: 'load_minutes', label: 'Minutes', align: 'right' },
                        { key: 'operation_count', label: 'Operations', align: 'right' },
                    ]"
                    :rows="load"
                    row-key="load_date"
                    empty="Nothing scheduled."
                    dense
                >
                    <template #cell:load_date="{ value }">{{ date(value) }}</template>
                    <template #cell:load_minutes="{ value }">{{ qty(value, 0) }}</template>
                </DataTable>
            </Card>

            <Card class="lg:col-span-3" title="Downtime history" subtitle="Attributed to the machine, because it feeds OEE" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'started_at', label: 'From' },
                        { key: 'ended_at', label: 'To' },
                        { key: 'minutes', label: 'Minutes', align: 'right' },
                        { key: 'reason', label: 'Reason' },
                        { key: 'category', label: 'Category' },
                    ]"
                    :rows="downtime"
                    row-key="id"
                    empty="No downtime logged."
                    dense
                >
                    <template #cell:started_at="{ value }">{{ datetime(value) }}</template>
                    <template #cell:ended_at="{ value }">{{ value ? datetime(value) : '—' }}</template>
                    <template #cell:category="{ value }">{{ titleCase(value) }}</template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
