<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, qty, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    certifications: { type: Array, default: () => [] },
    certifiedStock: { type: Array, default: () => [] },
    recentTransactions: { type: Array, default: () => [] },
});

const certColumns = [
    { key: 'scheme', label: 'Scheme' },
    { key: 'certificate_no', label: 'Certificate' },
    { key: 'issuing_body', label: 'Issued by' },
    { key: 'expires_on', label: 'Expires' },
    { key: 'days_to_expiry', label: 'Days', align: 'right' },
    { key: 'min_claim_pct', label: 'Min claim %', align: 'right' },
    { key: 'labelled_claim_pct', label: 'Labelled %', align: 'right' },
    { key: 'is_valid_today', label: 'Valid' },
];

const txColumns = [
    { key: 'scheme', label: 'Scheme' },
    { key: 'direction', label: 'Direction' },
    { key: 'qty', label: 'Certified qty', align: 'right' },
    { key: 'claim_pct', label: 'Claim %', align: 'right' },
    { key: 'period', label: 'Period' },
    { key: 'is_locked', label: 'Locked' },
];
</script>

<template>
    <AppLayout>
        <Head title="Compliance" />

        <template #title>Compliance &amp; chain of custody</template>
        <template #subtitle>Gate 2 — certified output must trace to certified input, unbroken</template>

        <template #actions>
            <Button variant="primary" href="/compliance/reconciliation">Reconciliation report</Button>
        </template>

        <div class="space-y-4">
            <Card title="Certificate registry" rule="BR-43" subtitle="A shipment cannot claim a scheme whose certificate has lapsed" :padded="false">
                <DataTable :columns="certColumns" :rows="certifications" row-key="id" empty="No certificates registered." dense>
                    <template #cell:scheme="{ value }">
                        <span class="font-medium text-ink-900">{{ value.replace('_', ' ') }}</span>
                    </template>
                    <template #cell:expires_on="{ value }">{{ date(value) }}</template>
                    <template #cell:days_to_expiry="{ value }">
                        <span :class="value < 0 ? 'text-rose-600 font-medium' : value < 60 ? 'text-amber-600' : ''">
                            {{ value }}
                        </span>
                    </template>
                    <template #cell:is_valid_today="{ value }">
                        <Badge :tone="value ? 'success' : 'danger'" :label="value ? 'Valid' : 'Expired'" />
                    </template>
                </DataTable>
            </Card>

            <div class="grid gap-4 lg:grid-cols-3">
                <Card title="Certified stock on hand" rule="I5" class="lg:col-span-1">
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="row in certifiedStock" :key="row.cert_scheme" class="flex items-center justify-between py-2">
                            <span class="font-medium text-ink-800">{{ row.cert_scheme.replace('_', ' ') }}</span>
                            <span class="text-right">
                                <span class="block tnum font-medium">{{ qty(row.qty) }}</span>
                                <span class="block text-[10px] text-ink-500">{{ row.lots }} lot(s)</span>
                            </span>
                        </li>
                        <li v-if="certifiedStock.length === 0" class="py-6 text-center text-ink-500">
                            No certified stock on hand.
                        </li>
                    </ul>
                </Card>

                <Card class="lg:col-span-2" title="Recent CoC transactions" rule="C1 · C3" :padded="false">
                    <DataTable :columns="txColumns" :rows="recentTransactions" row-key="id" empty="No transactions yet." dense>
                        <template #cell:scheme="{ value }">{{ value.replace('_', ' ') }}</template>
                        <template #cell:direction="{ value }">
                            <Badge
                                :tone="value === 'input' ? 'info' : value === 'output' ? 'success' : 'progress'"
                                :label="titleCase(value)"
                            />
                        </template>
                        <template #cell:qty="{ value }">{{ qty(value) }}</template>
                        <template #cell:period="{ row }">{{ row.period_year }}-{{ String(row.period_month).padStart(2, '0') }}</template>
                        <template #cell:is_locked="{ value }">
                            <Badge v-if="value" tone="neutral" label="Period closed" />
                            <span v-else class="text-ink-400">open</span>
                        </template>
                    </DataTable>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
