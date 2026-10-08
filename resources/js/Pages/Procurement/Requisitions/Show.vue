<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import CodeName from '@/Components/Ui/CodeName.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { date, qty, qtyRound, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';

const props = defineProps({
    requisition: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
});

const confirmTransition = useTransitionConfirm('purchase_requisition');

async function transition(to) {
    if (!(await confirmTransition(to, props.requisition.number))) return;

    router.post(`/purchase-requisitions/${props.requisition.id}/transition`, { to }, { preserveScroll: true });
}

/**
 * Rejecting sends the requisition back to the planner, and the remark is the only thing that
 * tells them why. `remarks` overwrites the requisition's own, so an empty box is left out of
 * the payload entirely rather than blanking what the planner wrote when they raised it.
 */
const rejectOpen = ref(false);
const rejectForm = useForm({ to: 'rejected', remarks: '' });

function reject() {
    rejectForm
        .transform((data) => (data.remarks ? data : { to: data.to }))
        .post(`/purchase-requisitions/${props.requisition.id}/transition`, {
            preserveScroll: true,
            onSuccess: () => {
                rejectOpen.value = false;
                rejectForm.reset('remarks');
            },
        });
}

const ORIGINS = { manual: 'raised by hand', mrp: 'from the material plan (MRP)', reorder_level: 'from a reorder level' };

const columns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'item_code', label: 'Material' },
    { key: 'qty', label: 'Requested', align: 'right' },
    { key: 'ordered_qty', label: 'Ordered', align: 'right' },
    { key: 'required_by', label: 'Wanted by' },
    { key: 'remarks', label: 'Remarks' },
];
</script>

<template>
    <AppLayout :crumb="requisition.number ?? 'Draft requisition'">
        <Head :title="requisition.number ?? 'Requisition'" />

        <template #title>{{ requisition.number ?? '(unnumbered)' }}</template>
        <template #subtitle>
            Raised {{ date(requisition.requested_on) }} · {{ ORIGINS[requisition.origin] ?? titleCase(requisition.origin) }}
            <span v-if="requisition.required_by"> · wanted by {{ date(requisition.required_by) }}</span>
        </template>

        <template #actions>
            <Badge :status="requisition.status" />

            <Button v-if="requisition.status === 'draft' && can('purchase_requisition.update')" size="sm" :href="`/purchase-requisitions/${requisition.id}/edit`">
                Edit
            </Button>
            <Button v-if="availableTransitions.includes('submitted')" size="sm" variant="primary" @click="transition('submitted')">
                Submit
            </Button>
            <!-- Approval is the manager's job, not the raiser's (06-rbac §5). -->
            <Button v-if="availableTransitions.includes('approved')" size="sm" variant="success" @click="transition('approved')">
                Approve
            </Button>
            <Button v-if="requisition.status === 'approved' && can('rfq.create')" size="sm" :href="`/rfqs/create?pr_id=${requisition.id}`">
                Raise RFQ
            </Button>
            <Button v-if="requisition.status === 'approved' && can('purchase_order.create')" size="sm" variant="primary" :href="`/purchase-orders/create?pr=${requisition.id}`">
                Raise a purchase order
            </Button>
            <!--
                The ways back. All three are in the state machine and none had a button, so a
                submitted requisition could only ever go forward: an approver who wanted
                changes had to approve it or leave it sitting.
            -->
            <Button v-if="availableTransitions.includes('draft')" size="sm" @click="transition('draft')">
                Return for changes
            </Button>
            <Button v-if="availableTransitions.includes('rejected')" size="sm" variant="danger" @click="rejectOpen = true">
                Reject
            </Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="transition('cancelled')">
                Cancel
            </Button>
        </template>

        <div class="space-y-4">
            <Card title="Lines" :padded="false">
                <DataTable :columns="columns" :rows="lines" row-key="id" empty="No lines." dense>
                    <template #cell:item_code="{ row }">
                        <CodeName :code="row.item_code" :name="row.item_name" />
                    </template>
                    <template #cell:qty="{ row }">{{ qtyRound(row.qty) }} {{ row.uom }}</template>
                    <template #cell:ordered_qty="{ row }">
                        <!-- Said in words as well as colour: nothing ordered yet, some of it, or all of it. -->
                        <span v-if="Number(row.ordered_qty) <= 0" class="text-ink-500">None yet</span>
                        <span v-else :class="Number(row.ordered_qty) >= Number(row.qty) ? 'text-emerald-700' : 'text-amber-700'">
                            {{ qtyRound(row.ordered_qty) }} {{ row.uom }}{{ Number(row.ordered_qty) >= Number(row.qty) ? '' : ' so far' }}
                        </span>
                    </template>
                    <template #cell:required_by="{ value }">{{ value ? date(value) : '—' }}</template>
                </DataTable>
            </Card>

            <Card v-if="requisition.remarks" title="Remarks">
                <p class="text-sm whitespace-pre-line text-ink-700">{{ requisition.remarks }}</p>
            </Card>
        </div>

        <Modal
            v-model:open="rejectOpen"
            title="Reject this requisition"
            subtitle="It goes back to the planner, who can revise it and submit again."
        >
            <FormField label="Remarks" :error="rejectForm.errors.remarks" hint="Replaces the requisition's remarks. Leave empty to keep what is there.">
                <textarea
                    v-model="rejectForm.remarks"
                    rows="3"
                    class="form-textarea"
                    placeholder="Quantities do not match the production plan — …"
                />
            </FormField>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="danger" :loading="rejectForm.processing" @click="reject">Reject</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
