<script setup>
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    report: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
});

function transition(to) {
    router.post(`/lab/reports/${props.report.id}/transition`, { to }, { preserveScroll: true });
}

const columns = [
    { key: 'test_code', label: 'Code' },
    { key: 'test_name', label: 'Test' },
    { key: 'method', label: 'Method' },
    { key: 'pass_value', label: 'Threshold', align: 'right' },
    { key: 'result_value', label: 'Result', align: 'right' },
    { key: 'result', label: 'Verdict', align: 'center' },
];
</script>

<template>
    <AppLayout>
        <Head :title="report.number ?? 'Test report'" />

        <template #title>{{ report.number ?? '(draft report)' }}</template>
        <template #subtitle>
            Tested {{ date(report.tested_on) }}
            <span v-if="report.customer"> · {{ report.customer }}</span>
            <span v-if="report.lot_no"> · Lot {{ report.lot_no }}</span>
        </template>

        <template #actions>
            <Badge :status="report.overall_result" />
            <Badge :status="report.status" />
            <Button
                v-if="availableTransitions.includes('issued')"
                size="sm"
                variant="primary"
                @click="transition('issued')"
            >
                Issue certificate
            </Button>
            <Button
                v-if="availableTransitions.includes('cancelled')"
                size="sm"
                variant="danger"
                @click="transition('cancelled')"
            >
                Cancel
            </Button>
        </template>

        <div class="space-y-4">
            <Card title="Test results" rule="QL-5 — verdict is computed, not typed" :padded="false">
                <DataTable :columns="columns" :rows="lines" row-key="id" empty="No results." dense>
                    <template #cell:result="{ value }">
                        <Badge :tone="value === 'pass' ? 'success' : value === 'fail' ? 'danger' : 'neutral'" :label="titleCase(value)" />
                    </template>
                    <template #cell:result_value="{ value }">
                        <span class="font-medium tnum">{{ value }}</span>
                    </template>
                </DataTable>
            </Card>

            <Card title="Details">
                <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs text-ink-500">Overall</dt><dd><Badge :tone="report.overall_result === 'pass' ? 'success' : report.overall_result === 'fail' ? 'danger' : 'neutral'" :label="titleCase(report.overall_result)" /></dd></div>
                    <div><dt class="text-xs text-ink-500">Technician</dt><dd>{{ report.technician ?? '—' }}</dd></div>
                    <div v-if="report.issued_at"><dt class="text-xs text-ink-500">Issued at</dt><dd>{{ date(report.issued_at) }}</dd></div>
                    <div v-if="report.remarks"><dt class="text-xs text-ink-500">Remarks</dt><dd>{{ report.remarks }}</dd></div>
                </dl>
            </Card>
        </div>
    </AppLayout>
</template>
