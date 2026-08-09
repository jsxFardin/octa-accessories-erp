<script setup>
import { Head } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, datetime, money, pcs, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ supplier: Object, items: Array, purchaseOrders: Array });

</script>

<template>
    <AppLayout>
        <Head :title="supplier.code" />

        <template #title>{{ supplier.code }} · {{ supplier.name }}</template>
        <template #subtitle>{{ supplier.country }}</template>

        <template #actions>
            <Button v-if="can('supplier.update')" size="sm" :href="`/suppliers/${supplier.id}/edit`">Edit</Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card title="Supplier items" rule="BR-26" subtitle="Lead time is per supplier-item, not global" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'code', label: 'Item' },
                        { key: 'supplier_code', label: 'Their code' },
                        { key: 'last_rate', label: 'Last rate', align: 'right' },
                        { key: 'lead_time_days', label: 'Lead days', align: 'right' },
                        { key: 'moq', label: 'MOQ', align: 'right' },
                    ]"
                    :rows="items"
                    row-key="id"
                    empty="No items linked."
                    dense
                >
                    <template #cell:last_rate="{ value }">{{ value ? money(value) : '—' }}</template>
                    <template #cell:moq="{ value }">{{ value ? qty(value) : '—' }}</template>
                </DataTable>
            </Card>

            <Card title="Purchase orders" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'number', label: 'Number' },
                        { key: 'order_date', label: 'Ordered' },
                        { key: 'total', label: 'Value', align: 'right' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="purchaseOrders"
                    row-key="id"
                    empty="No purchase orders."
                    dense
                >
                    <template #cell:order_date="{ value }">{{ date(value) }}</template>
                    <template #cell:total="{ value }">{{ money(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
