<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import { date, datetime, money, pcs, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ customer: Object, products: Array, openOrders: Array, outstanding: Number });

/**
 * A customer that is not active is invisible to every picker in the application, so a record
 * that looks perfectly normal here quietly cannot be quoted or ordered against. That has to
 * be said on the page, not discovered in an empty dropdown.
 */
const inquiryHref = computed(() => `/inquiries/create?customer=${props.customer.id}`);
const productHref = computed(() => `/products/create?customer=${props.customer.id}`);
</script>

<template>
    <AppLayout>
        <Head :title="customer.code" />

        <template #title>{{ customer.code }} · {{ customer.name }}</template>
        <template #subtitle>{{ customer.country }}</template>

        <template #actions>
            <Badge
                :tone="customer.is_active ? 'success' : 'neutral'"
                :label="customer.is_active ? 'Active' : 'Inactive'"
            />
            <Button v-if="can('inquiry.create') && customer.is_active" size="sm" variant="primary" :href="inquiryHref">
                New inquiry
            </Button>
            <Button v-if="can('customer.update')" size="sm" :href="`/customers/${customer.id}/edit`">Edit</Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <div
                v-if="!customer.is_active"
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 lg:col-span-3"
            >
                <p class="font-medium">This customer is inactive.</p>
                <p class="mt-0.5 text-xs">
                    Its history is intact, but it will not appear when anyone searches for a customer on an
                    inquiry, quotation or order. Edit it and tick <span class="font-medium">Active</span> to
                    make it selectable again.
                </p>
            </div>

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
                    <template #empty>
                        <EmptyState
                            icon="order"
                            title="Nothing on order for this customer"
                            :description="customer.is_active
                                ? 'Work reaches this page once an inquiry is quoted and the quotation is converted into an order.'
                                : 'Nothing can be ordered while the customer is inactive.'"
                            :action-label="can('inquiry.create') && customer.is_active ? 'New inquiry' : null"
                            :action-href="can('inquiry.create') && customer.is_active ? inquiryHref : null"
                        />
                    </template>

                    <template #cell:ordered_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:delivered_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:promised_date="{ value }">{{ date(value) }}</template>
                </DataTable>
            </Card>

            <Card class="lg:col-span-3" title="Products" subtitle="One product, one customer" :padded="false">
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
                    <template #empty>
                        <EmptyState
                            icon="product"
                            title="No products yet"
                            description="A product belongs to exactly one customer, and a quotation line can only name one of theirs. Nothing can be quoted until at least one exists."
                            :action-label="can('product.create') ? 'New product' : null"
                            :action-href="can('product.create') ? productHref : null"
                        />
                    </template>

                    <template #cell:product_type="{ value }">{{ titleCase(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
