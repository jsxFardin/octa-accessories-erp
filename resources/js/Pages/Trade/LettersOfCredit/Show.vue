<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, money, titleCase, todayIso } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    letter: { type: Object, required: true },
    amendments: { type: Array, default: () => [] },
    purchaseOrders: { type: Array, default: () => [] },
    shipments: { type: Array, default: () => [] },
    availablePurchaseOrders: { type: Array, default: () => [] },
});

/** The ladder, mirrored from the controller so the buttons offer only what will be accepted. */
const NEXT = {
    draft: ['applied', 'cancelled'],
    applied: ['opened', 'cancelled'],
    opened: ['shipped', 'closed', 'cancelled'],
    shipped: ['retired', 'closed'],
    retired: ['closed'],
};

const next = computed(() => NEXT[props.letter.status] ?? []);

const opening = ref(false);
const amending = ref(false);
const attaching = ref(false);

const openForm = useForm({ status: 'opened', lc_no: props.letter.lc_no ?? '', issued_on: '' });
const amendForm = useForm({
    amended_on: todayIso(),
    amount_delta: 0,
    new_expiry_date: '',
    new_last_shipment_date: '',
    charges_amount: 0,
    narrative: '',
});
const attachForm = useForm({ po_id: '', covered_amount: '' });

function move(status) {
    if (status === 'opened') {
        opening.value = true;

        return;
    }

    router.post(`/letters-of-credit/${props.letter.id}/transition`, { status }, { preserveScroll: true });
}

function confirmOpen() {
    openForm.post(`/letters-of-credit/${props.letter.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => (opening.value = false),
    });
}

function submitAmendment() {
    amendForm.post(`/letters-of-credit/${props.letter.id}/amend`, {
        preserveScroll: true,
        onSuccess: () => {
            amending.value = false;
            amendForm.reset();
        },
    });
}

function attach() {
    attachForm.post(`/letters-of-credit/${props.letter.id}/orders`, {
        preserveScroll: true,
        onSuccess: () => {
            attaching.value = false;
            attachForm.reset();
        },
    });
}

function detach(poId) {
    router.delete(`/letters-of-credit/${props.letter.id}/orders/${poId}`, { preserveScroll: true });
}

const covered = computed(() => props.purchaseOrders.reduce((sum, po) => sum + Number(po.covered_amount ?? 0), 0));
</script>

<template>
    <AppLayout>
        <Head :title="letter.number ?? 'Letter of credit'" />

        <template #title>{{ letter.number }}</template>
        <template #subtitle>
            {{ letter.supplier_name }} · {{ titleCase(letter.kind) }}
            <template v-if="letter.lc_no"> · bank ref {{ letter.lc_no }}</template>
        </template>

        <template #actions>
            <Badge :status="letter.status" />
            <Button v-if="can('letter_of_credit.amend') && ['applied', 'opened', 'shipped'].includes(letter.status)" size="sm" @click="amending = true">
                Amend
            </Button>
            <Button
                v-for="status in next"
                :key="status"
                size="sm"
                :variant="status === 'cancelled' ? 'danger' : 'primary'"
                @click="move(status)"
            >
                {{ titleCase(status) }}
            </Button>
            <Button v-if="can('letter_of_credit.update') && letter.status === 'draft'" size="sm" :href="`/letters-of-credit/${letter.id}/edit`">
                Edit
            </Button>
        </template>

        <div class="space-y-4">
            <Card title="Terms" :subtitle="`Opened against ${letter.bank_name ?? 'no bank account on file'}`">
                <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-ink-500">Face value</dt>
                        <dd class="tnum font-medium">{{ money(letter.amount) }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">After amendments</dt>
                        <dd class="tnum font-medium">{{ money(letter.current_amount) }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">Covered orders</dt>
                        <dd class="tnum font-medium">{{ money(covered) }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">Tolerance</dt>
                        <dd class="tnum font-medium">{{ letter.tolerance_pct }}%</dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">Last shipment</dt>
                        <dd class="font-medium">{{ letter.last_shipment_date ? date(letter.last_shipment_date) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">Expiry</dt>
                        <dd class="font-medium">{{ letter.effective_expiry ? date(letter.effective_expiry) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">Tenor</dt>
                        <dd class="tnum font-medium">{{ letter.tenor_days }} days</dd>
                    </div>
                    <div>
                        <dt class="text-ink-500">Charges</dt>
                        <dd class="tnum font-medium">{{ money(letter.charges_amount) }}</dd>
                    </div>
                </dl>

                <p v-if="letter.remarks" class="mt-3 text-sm text-ink-600">{{ letter.remarks }}</p>
            </Card>

            <Card
                title="Purchase orders covered"
                subtitle="One credit commonly pays for several orders to the same supplier"
                :padded="false"
            >
                <template #actions>
                    <Button v-if="can('letter_of_credit.update') && availablePurchaseOrders.length" size="sm" @click="attaching = true">
                        Attach order
                    </Button>
                </template>

                <DataTable
                    :columns="[
                        { key: 'number', label: 'Order' },
                        { key: 'order_date', label: 'Ordered' },
                        { key: 'total', label: 'Order value', align: 'right' },
                        { key: 'covered_amount', label: 'Covered', align: 'right' },
                        { key: 'status', label: 'Status' },
                        { key: 'actions', label: '', align: 'right' },
                    ]"
                    :rows="purchaseOrders"
                    row-key="id"
                    empty="No orders attached yet."
                    dense
                >
                    <template #cell:number="{ row, value }">
                        <a :href="`/purchase-orders/${row.id}`" class="font-medium text-brand-700 hover:underline">{{ value }}</a>
                    </template>
                    <template #cell:order_date="{ value }">{{ date(value) }}</template>
                    <template #cell:total="{ value }">{{ money(value) }}</template>
                    <template #cell:covered_amount="{ value }">{{ money(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                    <template #cell:actions="{ row }">
                        <button
                            v-if="can('letter_of_credit.update')"
                            class="text-xs text-rose-600 hover:underline"
                            @click.stop="detach(row.id)"
                        >
                            Remove
                        </button>
                    </template>
                </DataTable>
            </Card>

            <Card
                title="Amendments"
                subtitle="Appended, never merged — what the bank charged for and when the dates moved"
                :padded="false"
            >
                <DataTable
                    :columns="[
                        { key: 'amendment_no', label: '#', align: 'center' },
                        { key: 'amended_on', label: 'Date' },
                        { key: 'amount_delta', label: 'Value change', align: 'right' },
                        { key: 'new_last_shipment_date', label: 'New last shipment' },
                        { key: 'new_expiry_date', label: 'New expiry' },
                        { key: 'charges_amount', label: 'Charges', align: 'right' },
                        { key: 'narrative', label: 'Reason' },
                    ]"
                    :rows="amendments"
                    row-key="id"
                    empty="No amendments — the credit stands as opened."
                    dense
                >
                    <template #cell:amended_on="{ value }">{{ date(value) }}</template>
                    <template #cell:amount_delta="{ value }">{{ money(value) }}</template>
                    <template #cell:new_last_shipment_date="{ value }">{{ value ? date(value) : '—' }}</template>
                    <template #cell:new_expiry_date="{ value }">{{ value ? date(value) : '—' }}</template>
                    <template #cell:charges_amount="{ value }">{{ money(value) }}</template>
                    <template #cell:narrative="{ value }">{{ value ?? '—' }}</template>
                </DataTable>
            </Card>

            <Card title="Shipments" subtitle="Consignments moving against this credit" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'number', label: 'Shipment' },
                        { key: 'invoice_no', label: 'Invoice' },
                        { key: 'transport_doc_no', label: 'BL / AWB' },
                        { key: 'eta', label: 'ETA' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="shipments"
                    row-key="id"
                    :row-href="(row) => `/import-shipments/${row.id}`"
                    empty="Nothing shipped against this credit yet."
                    dense
                >
                    <template #cell:eta="{ value }">{{ value ? date(value) : '—' }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>

        <Modal v-model:open="opening" title="Open the credit" subtitle="The bank's own number is what every shipping document will quote.">
            <div class="space-y-3">
                <FormField label="Bank LC number" required :error="openForm.errors.lc_no">
                    <TextInput v-model="openForm.lc_no" />
                </FormField>
                <FormField label="Issued on" :error="openForm.errors.issued_on">
                    <TextInput v-model="openForm.issued_on" type="date" />
                </FormField>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="openForm.processing" @click="confirmOpen">Mark opened</Button>
            </template>
        </Modal>

        <Modal v-model:open="amending" width="max-w-2xl" title="Record an amendment" subtitle="Value, dates and what the bank charged for the change.">
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField label="Amended on" required :error="amendForm.errors.amended_on">
                    <TextInput v-model="amendForm.amended_on" type="date" />
                </FormField>
                <FormField label="Value change" hint="Negative to reduce the credit." :error="amendForm.errors.amount_delta">
                    <TextInput v-model="amendForm.amount_delta" type="number" step="0.0001" numeric />
                </FormField>
                <FormField label="New last shipment date" :error="amendForm.errors.new_last_shipment_date">
                    <TextInput v-model="amendForm.new_last_shipment_date" type="date" />
                </FormField>
                <FormField label="New expiry date" :error="amendForm.errors.new_expiry_date">
                    <TextInput v-model="amendForm.new_expiry_date" type="date" />
                </FormField>
                <FormField label="Bank charges" :error="amendForm.errors.charges_amount">
                    <TextInput v-model="amendForm.charges_amount" type="number" step="0.0001" numeric />
                </FormField>
                <FormField label="Reason" class="sm:col-span-2" :error="amendForm.errors.narrative">
                    <TextInput v-model="amendForm.narrative" />
                </FormField>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="amendForm.processing" @click="submitAmendment">Record amendment</Button>
            </template>
        </Modal>

        <Modal v-model:open="attaching" title="Attach a purchase order" subtitle="Only orders to this supplier can be covered by this credit.">
            <div class="space-y-3">
                <FormField label="Purchase order" required :error="attachForm.errors.po_id">
                    <SelectInput
                        v-model="attachForm.po_id"
                        :options="availablePurchaseOrders.map((po) => ({ value: po.id, label: `${po.number} · ${money(po.total)}` }))"
                    />
                </FormField>
                <FormField label="Covered amount" hint="Defaults to the order value." :error="attachForm.errors.covered_amount">
                    <TextInput v-model="attachForm.covered_amount" type="number" step="0.0001" numeric />
                </FormField>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="attachForm.processing" @click="attach">Attach</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
