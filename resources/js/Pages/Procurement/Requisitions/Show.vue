<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';

const props = defineProps({
    requisition: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
});

function transition(to) {
    router.post(`/purchase-requisitions/${props.requisition.id}/transition`, { to }, { preserveScroll: true });
}

const columns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'item_code', label: 'Item' },
    { key: 'qty', label: 'Requested', align: 'right' },
    { key: 'ordered_qty', label: 'Ordered', align: 'right' },
    { key: 'required_by', label: 'Required by' },
    { key: 'remarks', label: 'Remarks' },
];
</script>

<template>
    <AppLayout>
        <Head :title="requisition.number ?? 'Requisition'" />

        <template #title>{{ requisition.number ?? '(unnumbered)' }}</template>
        <template #subtitle>
            Raised {{ date(requisition.requested_on) }} · {{ titleCase(requisition.origin) }}
            <span v-if="requisition.required_by"> · required by {{ date(requisition.required_by) }}</span>
        </template>

        <template #actions>
            <Badge :status="requisition.status" />

            <Button v-if="requisition.status === 'draft' && can('purchase_requisition.update')" size="sm" :href="`/purchase-requisitions/${requisition.id}/edit`">
                Edit
            </Button>
            <Button v-if="requisition.status === 'draft' && can('purchase_requisition.submit')" size="sm" variant="primary" @click="transition('submitted')">
                Submit
            </Button>
            <!-- Approval is the manager's job, not the raiser's (06-rbac §5). -->
            <Button v-if="requisition.status === 'submitted' && can('purchase_requisition.approve')" size="sm" variant="success" @click="transition('approved')">
                Approve
            </Button>
            <Button v-if="requisition.status === 'approved' && can('rfq.create')" size="sm" :href="`/rfqs/create?pr_id=${requisition.id}`">
                Raise RFQ
            </Button>
            <Button v-if="requisition.status === 'approved' && can('purchase_order.create')" size="sm" variant="primary" href="/purchase-orders/create">
                Raise a purchase order
            </Button>
        </template>

        <div class="space-y-4">
            <Card title="Lines" :padded="false">
                <DataTable :columns="columns" :rows="lines" row-key="id" empty="No lines." dense>
                    <template #cell:item_code="{ row }">
                        <span class="font-medium text-ink-900">{{ row.item_code }}</span>
                        <span class="text-ink-500"> {{ row.item_name }}</span>
                    </template>
                    <template #cell:qty="{ row }">{{ qty(row.qty) }} {{ row.uom }}</template>
                    <template #cell:ordered_qty="{ row }">
                        <span :class="Number(row.ordered_qty) >= Number(row.qty) ? 'text-emerald-700' : 'text-amber-700'">
                            {{ qty(row.ordered_qty) }}
                        </span>
                    </template>
                    <template #cell:required_by="{ value }">{{ value ? date(value) : '—' }}</template>
                </DataTable>
            </Card>

            <Card v-if="requisition.remarks" title="Remarks">
                <p class="text-sm whitespace-pre-line text-ink-700">{{ requisition.remarks }}</p>
            </Card>
        </div>
    </AppLayout>
</template>
