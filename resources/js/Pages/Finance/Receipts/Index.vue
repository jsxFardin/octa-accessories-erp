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

const props = defineProps({ receipts: Object, filters: Object });

const columns = [
    { key: 'number', label: 'Number', sort: true },
    { key: 'customer_name', label: 'Customer' },
    { key: 'receipt_date', label: 'Date', sort: true },
    { key: 'amount', label: 'Amount', align: 'right', sort: true },
    { key: 'mode', label: 'Mode' },
];
</script>

<template>
    <AppLayout>
        <Head title="Receipts" />

        <template #title>Receipts</template>
        <template #subtitle>Allocated against invoices; the remainder is the customer\u2019s advance</template>

        <template #actions>
            <span />
        </template>

        <Card :padded="false">
            <!--
                A receipt allocates against invoices; with no invoice to allocate to, a create
                form would only produce unapplied cash. Left read-only until invoicing lands.
            -->
            <div class="border-b border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                <span class="font-medium">Receipts allocate against invoices.</span>
                Invoicing is Phase 3 sprint 17 (docs/10-roadmap.md); until it lands there is
                nothing for a receipt to be applied to, so this screen is read-only.
            </div>

            <FilterBar :filters="filters" :fields="[]" placeholder="Search receipt or reference number…" />

            <DataTable
                :columns="columns"
                :rows="receipts"
                row-key="id"
                empty="No receipts recorded."
            >
                <template #cell:number="{ row, value }"><span class="font-medium text-ink-900">{{ value ?? "(unnumbered)" }}</span></template>
                <template #cell:customer_name="{ row, value }">{{ row.customer?.name ?? "—" }}</template>
                <template #cell:receipt_date="{ row, value }">{{ date(value) }}</template>
                <template #cell:amount="{ row, value }">{{ money(value) }}</template>
                <template #empty>
                    <EmptyState
                        icon="receipt"
                        title="No receipts yet"
                        description="A receipt allocates customer payment against outstanding invoices."
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @clear-filters="router.get(window.location.pathname)"
                    />
                </template>
            </DataTable>
        </Card>
    </AppLayout>
</template>
