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

const props = defineProps({ sales_invoices: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'customer_name', label: 'Customer' },
    { key: 'invoice_date', label: 'Date', sort: true },
    { key: 'due_date', label: 'Due', sort: true },
    { key: 'total', label: 'Value', align: 'right', sort: true },
    { key: 'received_amount', label: 'Received', align: 'right' },
    { key: 'status', label: 'Status', sort: true },
];
</script>

<template>
    <AppLayout>
        <Head title="Invoices" />

        <template #title>Invoices</template>
        <template #subtitle>An AR subledger — enough for ageing, credit control and export</template>

        <template #actions>
            <span />
        </template>

        <Card :padded="false">
            <!--
                Invoicing is raised *from* a delivery challan, never typed: the quantities,
                rates and lot references have to be the ones that left the gate. The challan
                chain (packing list → challan) is not built yet, so there is deliberately no
                "New invoice" button here rather than a form that would invent its own lines.
            -->
            <div class="border-b border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                <span class="font-medium">Invoices are raised from a delivery challan.</span>
                Packing, challan and invoicing are Phase 2 sprint 13 / Phase 3 sprint 17
                (docs/10-roadmap.md) and are not built yet — this screen reads the AR subledger only.
            </div>

            <FilterBar :filters="filters" :fields="[{ key: 'status', label: 'Status', options: ['draft','issued','partially_paid','paid','overdue','cancelled'].map((s) => ({ value: s, label: titleCase(s) })) }]" placeholder="Search invoice or Mushak number…" />

            <DataTable
                :columns="columns"
                :rows="sales_invoices"
                row-key="id"
                empty="No invoices issued."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:customer_name="{ row, value }">{{ row.customer?.name ?? "—" }}</template>
                <template #cell:invoice_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:due_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:total="{ row, value }">{{ money(value) }}</template>
                <template #cell:received_amount="{ row, value }">{{ money(value) }}</template>
                <template #cell:status="{ row, value }"><Badge :status="value" /></template>
                <template #empty>
                    <EmptyState
                        icon="invoice"
                        title="No invoices yet"
                        description="Invoices are raised from a delivery challan — the quantities have to be the ones that left the gate."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
