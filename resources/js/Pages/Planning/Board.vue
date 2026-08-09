<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import { date, pcs } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    machines: { type: Array, default: () => [] },
    dates: { type: Array, default: () => [] },
    cells: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    groups: { type: Array, default: () => [] },
    unscheduled: { type: Array, default: () => [] },
});

const cellIndex = computed(() => {
    const map = {};

    for (const cell of props.cells) {
        map[`${cell.machine_id}|${cell.date}`] = cell;
    }

    return map;
});

function cell(machineId, date) {
    return cellIndex.value[`${machineId}|${date}`];
}

/**
 * BR-27 — the board blocks scheduling past 100%. The colour ramp exists so a planner sees the
 * over-committed machine before they add to it, not after the delivery slips.
 */
function tone(cell) {
    if (!cell || cell.is_holiday) return 'bg-slate-100 text-ink-400';
    if (cell.over_capacity) return 'bg-rose-100 text-rose-900 ring-1 ring-rose-300';
    if (cell.utilisation_pct >= 85) return 'bg-amber-100 text-amber-900';
    if (cell.utilisation_pct > 0) return 'bg-emerald-50 text-emerald-900';

    return 'bg-white text-slate-300';
}

function shiftWindow(days) {
    router.get('/planning', { ...props.filters, days }, { preserveState: true, replace: true });
}

function weekday(value) {
    return new Date(value).toLocaleDateString('en-GB', { weekday: 'short' });
}
</script>

<template>
    <AppLayout>
        <Head title="Planning board" />

        <template #title>Planning board</template>
        <template #subtitle>Machine × day utilisation — available minutes are discounted by planned downtime and machine efficiency (BR-27)</template>

        <template #actions>
            <div class="w-36">
                <SelectInput
                    :model-value="filters.group ?? ''"
                    placeholder="All groups"
                    :options="groups"
                    value-key="id"
                    label-key="name"
                    @update:model-value="router.get('/planning', { ...filters, group: $event || undefined }, { preserveState: true, replace: true })"
                />
            </div>

            <div class="w-28">
                <SelectInput
                    :model-value="filters.days"
                    :placeholder="null"
                    :options="[7, 10, 14, 21].map((n) => ({ value: n, label: `${n} days` }))"
                    @update:model-value="shiftWindow($event)"
                />
            </div>
        </template>

        <div class="space-y-4">
            <Card :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50">
                                <th class="sticky left-0 z-10 bg-slate-50 px-3 py-2 text-left font-semibold text-ink-700">
                                    Machine
                                </th>
                                <th
                                    v-for="d in dates"
                                    :key="d"
                                    class="px-1 py-2 text-center font-semibold whitespace-nowrap text-ink-700"
                                >
                                    <div>{{ weekday(d) }}</div>
                                    <div class="font-normal text-ink-400">{{ d.slice(5) }}</div>
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="machine in machines" :key="machine.id">
                                <td class="sticky left-0 z-10 bg-white px-3 py-1.5 whitespace-nowrap">
                                    <div class="font-medium text-ink-800">{{ machine.code }}</div>
                                    <div class="text-[10px] text-ink-400">
                                        {{ machine.group_code }} · {{ machine.efficiency_pct }}% eff
                                    </div>
                                </td>

                                <td v-for="d in dates" :key="d" class="p-0.5">
                                    <div
                                        class="rounded px-1 py-1.5 text-center tnum"
                                        :class="tone(cell(machine.id, d))"
                                        :title="cell(machine.id, d)
                                            ? `${cell(machine.id, d).load} of ${cell(machine.id, d).available} min · ${cell(machine.id, d).operations} ops`
                                            : ''"
                                    >
                                        <div class="text-[11px] font-semibold">
                                            {{ cell(machine.id, d)?.is_holiday ? '—' : `${Math.round(cell(machine.id, d)?.utilisation_pct ?? 0)}%` }}
                                        </div>
                                        <div class="text-[9px] opacity-70">
                                            {{ cell(machine.id, d)?.operations || '' }}
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="machines.length === 0">
                                <td :colspan="dates.length + 1" class="px-3 py-10 text-center text-ink-500">
                                    No active machines in this group.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card title="Unscheduled operations" subtitle="Waiting for a machine and a slot" :padded="false">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="op in unscheduled" :key="op.id" class="flex flex-wrap items-center gap-3 px-3 py-2">
                        <Link :href="`/job-cards/${op.job_card_id}`" class="font-medium text-brand-700">
                            {{ op.number ?? '(unnumbered)' }}
                        </Link>
                        <span class="text-ink-700">{{ op.name }}</span>
                        <span class="tnum text-xs text-ink-500">{{ pcs(op.planned_qty) }}</span>
                        <span class="ml-auto text-xs text-ink-500">due {{ date(op.due_date) }}</span>
                    </li>
                    <li v-if="unscheduled.length === 0" class="px-3 py-6 text-center text-ink-500">
                        Everything open is scheduled.
                    </li>
                </ul>
            </Card>
        </div>
    </AppLayout>
</template>
