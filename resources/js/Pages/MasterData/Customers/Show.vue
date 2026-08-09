<script setup>
import { Head } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, datetime, money, pcs, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ customer: Object, products: Array, openOrders: Array, outstanding: Number });

</script>

<template>
    <AppLayout>
        <Head :title="customer.code" />

        <template #title>{{ customer.code }} · {{ customer.name }}</template>
        <template #subtitle>{{ customer.country }}</template>

        <template #actions>
            <Button v-if="can('customer.update')" size="sm" :href="`/customers/${customer.id}/edit`">Edit</Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card title="Commercial guard rails" rule="BR-21 · BR-44 · BR-46">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-500">Credit limit</dt><dd class="tnum">{{ money(customer.credit_limit) }}</dd></div>
                    <div class="flex justify-between">
                        <dt class="text-ink-500">Outstanding</dt>
                        <dd class="tnum font-medium" :class="outstanding > customer.credit_limit && customer.credit_limit > 0 ? 'text-rose-600' : ''">
                            {{ money(outstanding) }}
                        </dd>
                    </div>
                    <div class="flex justify-between"><dt class="text-ink-500">Minimum order value</dt><dd class="tnum">{{ money(customer.min_order_value) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Delivery tolerance</dt><dd class="tnum">−{{ customer.under_tolerance_pct }}% / +{{ customer.over_tolerance_pct }}%</dd></div>
                </dl>
            </Card>

            <Card class="lg:col-span-2" title="Open order book" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'so_number', label: 'Order' },
                        { key: 'product_code', label: 'Product' },
                        { key: 'ordered_qty', label: 'Ordered', align: 'right' },
                        { key: 'delivered_qty', label: 'Delivered', align: 'right' },
                        { key: 'delivered_pct', label: '%', align: 'right' },
                        { key: 'promised_date', label: 'Promised' },
                    ]"
                    :rows="openOrders"
                    row-key="sales_order_line_id"
                    empty="No open orders."
                    dense
                >
                    <template #cell:ordered_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:delivered_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:promised_date="{ value }">{{ date(value) }}</template>
                </DataTable>
            </Card>

            <Card class="lg:col-span-3" title="Products" subtitle="One product, one customer (P1)" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'code', label: 'Code' },
                        { key: 'name', label: 'Name' },
                        { key: 'product_type', label: 'Type' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="products"
                    row-key="id"
                    :row-href="(row) => `/products/${row.id}`"
                    empty="No products defined for this customer."
                    dense
                >
                    <template #cell:product_type="{ value }">{{ titleCase(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
