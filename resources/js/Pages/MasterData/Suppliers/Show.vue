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

const props = defineProps({ supplier: Object, items: Array, purchaseOrders: Array });

/**
 * An order may be *drafted* against any supplier, but 05-workflows §7 refuses to submit one to
 * a supplier nobody has approved. Offering the action to an unapproved supplier would walk the
 * buyer into that wall after they had typed the lines, so the page says so up front instead.
 */
const canOrder = computed(
    () => props.supplier.is_active && props.supplier.is_approved && can('purchase_order.create'),
);

const orderHref = computed(() => `/purchase-orders/create?supplier=${props.supplier.id}`);
</script>

<template>
    <AppLayout>
        <Head :title="supplier.code" />

        <template #title>{{ supplier.code }} · {{ supplier.name }}</template>
        <template #subtitle>{{ supplier.country }}</template>

        <template #actions>
            <Badge
                :tone="supplier.is_active ? 'success' : 'neutral'"
                :label="supplier.is_active ? 'Active' : 'Inactive'"
            />
            <Badge
                :tone="supplier.is_approved ? 'success' : 'warning'"
                :label="supplier.is_approved ? 'Approved' : 'Not approved'"
            />
            <Button v-if="canOrder" size="sm" variant="primary" :href="orderHref">Create purchase order</Button>
            <Button v-if="can('supplier.update')" size="sm" :href="`/suppliers/${supplier.id}/edit`">Edit</Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-2">
            <div
                v-if="!supplier.is_approved"
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 lg:col-span-2"
            >
                <p class="font-medium">This supplier is not approved.</p>
                <p class="mt-0.5 text-xs">
                    An order can be drafted against them, but it cannot be submitted until Purchasing approves
                    them (05-workflows §7). Approve them on the edit screen first.
                </p>
            </div>

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
                    <template #cell:last_rate="{ row, value }">{{ value ? money(value, row.currency) : '—' }}</template>
                    <template #cell:moq="{ value }">{{ value ? qty(value) : '—' }}</template>
                </DataTable>
            </Card>

            <Card title="Purchase orders" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'number', label: 'Number' },
                        { key: 'order_date', label: 'Ordered' },
                        { key: 'total', label: 'Value', align: 'right' },
                        { key: 'currency', label: 'Currency' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="purchaseOrders"
                    row-key="id"
                    empty="No purchase orders."
                    dense
                >
                    <template #empty>
                        <EmptyState
                            icon="purchase-order"
                            title="Nothing has been ordered from this supplier"
                            :description="canOrder
                                ? 'An order opens with this supplier already chosen, and pulls from any approved requisition still waiting to be bought.'
                                : supplier.is_approved
                                    ? 'Nothing has been bought from them yet.'
                                    : 'They must be approved before an order can be submitted to them.'"
                            :action-label="canOrder ? 'Create purchase order' : null"
                            :action-href="canOrder ? orderHref : null"
                        />
                    </template>

                    <template #cell:order_date="{ value }">{{ date(value) }}</template>
                    <!-- BR-50 — the order's own currency, never the factory's by default. -->
                    <template #cell:total="{ row, value }">{{ money(value, row.currency) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
