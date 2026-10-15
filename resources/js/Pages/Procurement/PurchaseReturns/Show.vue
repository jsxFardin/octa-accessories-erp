<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import ActivityTrail from '@/Components/Ui/ActivityTrail.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, money, qty as formatQty } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    purchaseReturn: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    movements: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
    trail: { type: Array, default: () => [] },
});

const base = `/purchase-returns/${props.purchaseReturn.id}`;
const confirmTransition = useTransitionConfirm('purchase_return');

async function post() {
    if (!(await confirmTransition('posted', props.purchaseReturn.number ?? 'this return'))) return;

    router.post(`${base}/transition`, { to: 'posted' }, { preserveScroll: true });
}

const cancelOpen = ref(false);
const cancelForm = useForm({ to: 'cancelled', reason: '' });

const debitOpen = ref(false);
const debitForm = useForm({ debit_note_no: props.purchaseReturn.debit_note_no ?? '' });

const lineColumns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'item_code', label: 'Item' },
    { key: 'lot_no', label: 'Lot' },
    { key: 'qty', label: 'Returned', align: 'right' },
    { key: 'rate', label: 'Rate', align: 'right' },
    { key: 'value', label: 'Value', align: 'right' },
];
</script>

<template>
    <AppLayout :crumb="purchaseReturn.number ?? 'Draft return'">
        <Head :title="purchaseReturn.number ?? 'Supplier return'" />

        <template #title>{{ purchaseReturn.number ?? '(unnumbered)' }}</template>
        <template #subtitle>
            <Link v-if="purchaseReturn.supplier" :href="`/suppliers/${purchaseReturn.supplier.id}`" class="doc-link">{{ purchaseReturn.supplier.name }}</Link>
            · returned {{ date(purchaseReturn.returned_on) }}
            <template v-if="purchaseReturn.grn"> · from <Link :href="`/grns/${purchaseReturn.grn.id}`" class="doc-link">{{ purchaseReturn.grn.number }}</Link></template>
        </template>

        <template #actions>
            <Badge :status="purchaseReturn.status" />
            <Button v-if="availableTransitions.includes('posted')" size="sm" variant="primary" @click="post">Post — take the goods out of stock</Button>
            <Button v-if="purchaseReturn.status !== 'cancelled' && can('purchase_return.update')" size="sm" @click="debitOpen = true">{{ purchaseReturn.debit_note_no ? 'Change debit note' : 'Record debit note' }}</Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="cancelOpen = true">Cancel</Button>
            <ActivityTrail :entries="trail" />
        </template>

        <div class="space-y-4">
            <Card>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs text-ink-500">Supplier</dt><dd class="mt-0.5 font-medium text-ink-900">{{ purchaseReturn.supplier?.name ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-xs text-ink-500">Goods receipt</dt>
                        <dd class="mt-0.5"><Link v-if="purchaseReturn.grn" :href="`/grns/${purchaseReturn.grn.id}`" class="doc-link-quiet">{{ purchaseReturn.grn.number }}</Link> <Badge v-if="purchaseReturn.grn" :status="purchaseReturn.grn.status" /></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Debit note</dt>
                        <dd class="mt-0.5" :class="purchaseReturn.debit_note_no ? 'font-mono text-xs text-ink-900' : 'text-amber-700'">{{ purchaseReturn.debit_note_no ?? (purchaseReturn.status === 'posted' ? 'Awaited from Accounts' : 'Not yet raised') }}</dd>
                    </div>
                    <div><dt class="text-xs text-ink-500">Raised by</dt><dd class="mt-0.5 text-ink-900">{{ purchaseReturn.raised_by ?? '—' }}</dd></div>
                </dl>
                <p class="mt-3 border-t border-slate-100 pt-3 text-sm whitespace-pre-line text-ink-700">{{ purchaseReturn.reason }}</p>
            </Card>

            <Card title="Lines" :padded="false" subtitle="Each line names the lot the goods go back from, valued at the receipt rate">
                <DataTable :columns="lineColumns" :rows="lines" row-key="id" empty="No lines." dense>
                    <template #cell:item_code="{ row, value }"><span class="font-medium text-ink-900">{{ value }}</span> <span class="text-ink-500">{{ row.item_name }}</span></template>
                    <template #cell:lot_no="{ row, value }"><span class="font-mono text-xs">{{ value }}</span> <Badge v-if="row.lot_status" :status="row.lot_status" /><span class="block text-xs text-ink-500">{{ formatQty(row.balance_qty) }} left in stock</span></template>
                    <template #cell:qty="{ value }"><span class="tnum">{{ formatQty(value) }}</span></template>
                    <template #cell:rate="{ value }"><span class="tnum">{{ money(value) }}</span></template>
                    <template #cell:value="{ row }"><span class="tnum font-medium">{{ money(Number(row.qty) * Number(row.rate)) }}</span></template>
                </DataTable>
            </Card>

            <Card title="Stock taken out" subtitle="Posted against the lot the goods came in on, at that lot's cost">
                <ul v-if="movements.length" class="divide-y divide-slate-100 text-sm">
                    <li v-for="movement in movements" :key="movement.id" class="flex items-center justify-between py-2">
                        <span class="text-ink-600">{{ date(movement.occurred_at) }}</span>
                        <span class="tnum">{{ formatQty(movement.qty) }} @ {{ money(movement.unit_cost) }}</span>
                        <span class="font-medium tnum">{{ money(movement.value) }}</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-ink-500">Nothing yet. Stock moves when the return is posted.</p>
            </Card>
        </div>

        <Modal v-model:open="cancelOpen" title="Cancel this return" subtitle="Nothing has been posted, so nothing is unwound.">
            <FormField label="Reason" :error="cancelForm.errors.reason" required><textarea v-model="cancelForm.reason" rows="2" class="form-textarea" /></FormField>
            <template #footer="{ close }">
                <Button @click="close">Keep it</Button>
                <Button variant="danger" :disabled="!cancelForm.reason" :loading="cancelForm.processing" @click="cancelForm.post(`${base}/transition`, { onSuccess: () => (cancelOpen = false) })">Cancel the return</Button>
            </template>
        </Modal>

        <Modal v-model:open="debitOpen" title="Debit note" subtitle="The reference Accounts raised against the supplier for these goods.">
            <FormField label="Debit note number" :error="debitForm.errors.debit_note_no" required><TextInput v-model="debitForm.debit_note_no" /></FormField>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :disabled="!debitForm.debit_note_no" :loading="debitForm.processing" @click="debitForm.put(`${base}/debit-note`, { onSuccess: () => (debitOpen = false) })">Save</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
