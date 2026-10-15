<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import ActivityTrail from '@/Components/Ui/ActivityTrail.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, money, pct, titleCase, todayIso } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useGuardedAction } from '@/composables/useGuardedAction';

const props = defineProps({
    letter: { type: Object, required: true },
    amendments: { type: Array, default: () => [] },
    salesOrders: { type: Array, default: () => [] },
    availableSalesOrders: { type: Array, default: () => [] },
    backToBack: { type: Array, default: () => [] },
    btbLimitPct: { type: Number, default: 75 },
    /** limit, drawn, realised, available, invoices. */
    position: { type: Object, default: null },
    invoices: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
    trail: { type: Array, default: () => [] },
});

const base = `/export-lcs/${props.letter.id}`;
const name = props.letter.number;
const canMove = (to) => props.availableTransitions.includes(to);
const live = computed(() => ['advised', 'accepted', 'shipped', 'presented', 'discrepant'].includes(props.letter.status));

const { busy, run } = useGuardedAction();

function accept() {
    run('accepted', { title: `Accept ${name}?`, message: 'The terms are recorded as workable. From here they change only through an amendment.', confirmLabel: 'Accept credit' },
        (done) => router.post(`${base}/transition`, { status: 'accepted' }, { preserveScroll: true, ...done }));
}

function close() {
    run('closed', { title: `Close ${name}?`, message: 'Nothing more is recorded against it. This cannot be undone.', confirmLabel: 'Close credit', tone: 'danger' },
        (done) => router.post(`${base}/transition`, { status: 'closed' }, { preserveScroll: true, ...done }));
}

const presenting = ref(false);
const presentForm = useForm({ status: 'presented', presented_on: todayIso() });
const discrepancyOpen = ref(false);
const discrepancyForm = useForm({ status: 'discrepant', reason: '' });
const realising = ref(false);
const realiseForm = useForm({ status: 'realised', realised_on: todayIso(), realisation_ref: '' });
const cancelOpen = ref(false);
const cancelForm = useForm({ status: 'cancelled', reason: '' });
const amending = ref(false);
const amendForm = useForm({ amended_on: todayIso(), amount_delta: 0, new_expiry_date: '', new_last_shipment_date: '', charges_amount: 0, narrative: '' });
const attaching = ref(false);
const attachForm = useForm({ sales_order_id: '', covered_amount: '' });

const post = (form, flag) => form.post(`${base}/transition`, { preserveScroll: true, onSuccess: () => { flag.value = false; form.reset(); } });

function detach(order) {
    run(`detach-${order.id}`, { title: `Remove ${order.number} from the credit?`, message: 'The order is no longer covered by this credit. It can be added again.', confirmLabel: 'Remove order', tone: 'danger' },
        (done) => router.delete(`${base}/orders/${order.id}`, { preserveScroll: true, ...done }));
}

const covered = computed(() => props.salesOrders.reduce((sum, so) => sum + Number(so.covered_amount ?? 0), 0));
const btbTotal = computed(() => props.backToBack.reduce((sum, lc) => sum + Number(lc.amount ?? 0), 0));
const btbLimit = computed(() => Number(props.letter.current_amount) * props.btbLimitPct / 100);
</script>

<template>
    <AppLayout :crumb="letter.number">
        <Head :title="letter.number" />

        <template #title>{{ letter.number }}</template>
        <template #subtitle>
            <Link v-if="letter.customer" :href="`/customers/${letter.customer.id}`" class="doc-link">{{ letter.customer.name }}</Link>
            · {{ titleCase(letter.kind) }} · bank no. {{ letter.lc_no }}
        </template>

        <template #actions>
            <Badge :status="letter.status" />
            <Button v-if="canMove('accepted') && can('export_lc.accept')" size="sm" variant="primary" :loading="busy === 'accepted'" @click="accept">Accept credit</Button>
            <Button v-if="canMove('presented') && can('export_lc.update')" size="sm" variant="primary" @click="presenting = true">{{ letter.status === 'discrepant' ? 'Re-present documents' : 'Documents presented' }}</Button>
            <Button v-if="canMove('realised') && can('export_lc.realise')" size="sm" variant="primary" @click="realising = true">Proceeds realised</Button>
            <Button v-if="canMove('discrepant') && can('export_lc.update')" size="sm" @click="discrepancyOpen = true">Bank found a discrepancy</Button>
            <Button v-if="can('export_lc.amend') && live" size="sm" @click="amending = true">Amend</Button>
            <Button v-if="can('export_lc.update') && letter.status !== 'closed' && letter.status !== 'cancelled'" size="sm" :href="`${base}/edit`">Edit</Button>
            <Button v-if="canMove('closed') && can('export_lc.update')" size="sm" :loading="busy === 'closed'" @click="close">Close</Button>
            <Button v-if="canMove('cancelled') && can('export_lc.update')" size="sm" variant="danger" @click="cancelOpen = true">Cancel</Button>
            <ActivityTrail :entries="trail" />
        </template>

        <div class="space-y-4">
            <div v-if="letter.status === 'shipped'" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
                Shipped. The documents must reach the bank within {{ letter.latest_presentation_days }} days of shipment; record the presentation once they have.
            </div>
            <div v-else-if="letter.status === 'discrepant'" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-900">
                The bank found a discrepancy. Correct the documents and present them again.
            </div>

            <Card title="Terms" :subtitle="`Issued by ${letter.issuing_bank ?? 'the customer\'s bank'} · advised to ${letter.bank_name ?? 'no account on file'}`">
                <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-ink-500">Face value</dt><dd class="tnum font-medium">{{ money(letter.amount, letter.currency) }}</dd></div>
                    <div><dt class="text-ink-500">After amendments</dt><dd class="tnum font-medium">{{ money(letter.current_amount, letter.currency) }}</dd></div>
                    <div><dt class="text-ink-500">Covered orders</dt><dd class="tnum font-medium">{{ money(covered, letter.currency) }}</dd></div>
                    <div><dt class="text-ink-500">Tolerance</dt><dd class="tnum font-medium">{{ pct(letter.tolerance_pct, 1) }}</dd></div>
                    <div><dt class="text-ink-500">Last shipment</dt><dd class="font-medium">{{ letter.last_shipment_date ? date(letter.last_shipment_date) : '—' }}</dd></div>
                    <div><dt class="text-ink-500">Expiry</dt><dd class="font-medium">{{ letter.effective_expiry ? date(letter.effective_expiry) : '—' }}</dd></div>
                    <div><dt class="text-ink-500">Tenor</dt><dd class="tnum font-medium">{{ letter.tenor_days }} days</dd></div>
                    <div><dt class="text-ink-500">Presentation</dt><dd class="tnum font-medium">{{ letter.latest_presentation_days }} days after shipment</dd></div>
                    <div><dt class="text-ink-500">Partial shipment</dt><dd class="font-medium">{{ letter.partial_shipment ? 'Allowed' : 'Not allowed' }}</dd></div>
                    <div><dt class="text-ink-500">Transhipment</dt><dd class="font-medium">{{ letter.transhipment ? 'Allowed' : 'Not allowed' }}</dd></div>
                    <div><dt class="text-ink-500">Terms</dt><dd class="font-medium">{{ [letter.incoterm, letter.port_of_loading, letter.port_of_discharge].filter(Boolean).join(' · ') || '—' }}</dd></div>
                    <div><dt class="text-ink-500">Proceeds</dt><dd class="font-medium" :class="letter.realised_on ? 'text-emerald-700' : 'text-ink-900'">{{ letter.realised_on ? `Realised ${date(letter.realised_on)} · ${letter.realisation_ref}` : (letter.presented_on ? `Presented ${date(letter.presented_on)}` : 'Not yet presented') }}</dd></div>
                </dl>
                <p v-if="letter.remarks" class="mt-3 text-sm text-ink-600">{{ letter.remarks }}</p>

                <!-- Drawn and paid: what the invoices took and what the bank sent back. -->
                <dl v-if="position" class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-4">
                    <div><dt class="text-ink-500">Can be drawn to</dt><dd class="tnum font-medium">{{ money(position.limit, letter.currency) }}</dd></div>
                    <div><dt class="text-ink-500">Drawn by invoices</dt><dd class="tnum font-medium">{{ money(position.drawn, letter.currency) }} <span class="text-xs font-normal text-ink-500">· {{ position.invoices }} {{ position.invoices === 1 ? 'invoice' : 'invoices' }}</span></dd></div>
                    <div><dt class="text-ink-500">Available</dt><dd class="tnum font-medium" :class="position.available < 0 ? 'text-rose-700' : ''">{{ money(position.available, letter.currency) }}</dd></div>
                    <div><dt class="text-ink-500">Realised</dt><dd class="tnum font-medium" :class="position.realised > 0 ? 'text-emerald-700' : ''">{{ money(position.realised, letter.currency) }}</dd></div>
                </dl>
            </Card>

            <Card v-if="invoices.length" title="Invoices drawn on this credit" :padded="false">
                <DataTable :columns="[{ key: 'number', label: 'Invoice' }, { key: 'invoice_date', label: 'Date' }, { key: 'total', label: 'Total', align: 'right' }, { key: 'received_amount', label: 'Received', align: 'right' }, { key: 'status', label: 'Status' }]" :rows="invoices" row-key="id" :row-href="(row) => `/invoices/${row.id}`" dense>
                    <template #cell:number="{ value }">{{ value ?? '(draft)' }}</template>
                    <template #cell:invoice_date="{ value }">{{ date(value) }}</template>
                    <template #cell:total="{ value }">{{ money(value, letter.currency) }}</template>
                    <template #cell:received_amount="{ value }">{{ money(value, letter.currency) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>

            <Card title="Sales orders covered" subtitle="One credit commonly pays for several orders from the same customer, in its own currency" :padded="false">
                <template #actions>
                    <Button v-if="can('export_lc.update') && availableSalesOrders.length && live" size="sm" @click="attaching = true">Attach order</Button>
                </template>
                <DataTable
                    :columns="[
                        { key: 'number', label: 'Order' }, { key: 'order_date', label: 'Ordered' }, { key: 'delivery_date', label: 'Delivery' },
                        { key: 'total', label: 'Order value', align: 'right' }, { key: 'covered_amount', label: 'Covered', align: 'right' },
                        { key: 'status', label: 'Status' }, { key: 'actions', label: '', align: 'right' },
                    ]"
                    :rows="salesOrders" row-key="id" empty="No orders attached yet." dense
                >
                    <template #cell:number="{ row, value }"><Link :href="`/sales-orders/${row.id}`" class="doc-link-quiet">{{ value ?? '(draft)' }}</Link></template>
                    <template #cell:order_date="{ value }">{{ date(value) }}</template>
                    <template #cell:delivery_date="{ value }">
                        <span :class="value && letter.last_shipment_date && value > letter.last_shipment_date ? 'font-medium text-rose-700' : ''">{{ value ? date(value) : '—' }}</span>
                        <span v-if="value && letter.last_shipment_date && value > letter.last_shipment_date" class="block text-xs text-rose-700">after last shipment date</span>
                    </template>
                    <template #cell:total="{ row, value }">{{ money(value, row.currency) }}</template>
                    <template #cell:covered_amount="{ value }">{{ money(value, letter.currency) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                    <template #cell:actions="{ row }">
                        <button v-if="can('export_lc.update') && live" type="button" class="rounded px-1.5 py-1 text-xs text-rose-700 hover:underline disabled:opacity-50" :disabled="busy !== null" @click.stop="detach(row)">Remove</button>
                    </template>
                </DataTable>
            </Card>

            <Card title="Amendments" subtitle="Appended, never merged — what the bank charged for and when the dates moved" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'amendment_no', label: '#', align: 'center' }, { key: 'amended_on', label: 'Date' }, { key: 'amount_delta', label: 'Value change', align: 'right' },
                        { key: 'new_last_shipment_date', label: 'New last shipment' }, { key: 'new_expiry_date', label: 'New expiry' }, { key: 'charges_amount', label: 'Charges', align: 'right' }, { key: 'narrative', label: 'Reason' },
                    ]"
                    :rows="amendments" row-key="id" empty="No amendments — the credit stands as advised." dense
                >
                    <template #cell:amended_on="{ value }">{{ date(value) }}</template>
                    <template #cell:amount_delta="{ value }">{{ money(value, letter.currency) }}</template>
                    <template #cell:new_last_shipment_date="{ value }">{{ value ? date(value) : '—' }}</template>
                    <template #cell:new_expiry_date="{ value }">{{ value ? date(value) : '—' }}</template>
                    <template #cell:charges_amount="{ value }">{{ money(value, letter.currency) }}</template>
                    <template #cell:narrative="{ value }">{{ value ?? '—' }}</template>
                </DataTable>
            </Card>

            <Card title="Back-to-back import credits" :subtitle="`Opened against this master credit · ${money(btbTotal, letter.currency)} of a ${pct(btbLimitPct, 0)} limit (${money(btbLimit, letter.currency)})`" :padded="false">
                <DataTable
                    :columns="[{ key: 'number', label: 'Credit' }, { key: 'supplier', label: 'Supplier' }, { key: 'amount', label: 'Amount', align: 'right' }, { key: 'status', label: 'Status' }]"
                    :rows="backToBack" row-key="id" :row-href="(row) => `/letters-of-credit/${row.id}`" empty="No import credit names this one as its master yet." dense
                >
                    <template #cell:number="{ row, value }">{{ value }} <span class="text-xs text-ink-500">· {{ row.lc_no ?? 'not opened' }}</span></template>
                    <template #cell:amount="{ row, value }"><span class="tnum" :class="btbTotal > btbLimit ? 'text-rose-700' : ''">{{ money(value, row.currency) }}</span></template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>

        <Modal v-model:open="presenting" title="Documents presented" subtitle="The date the full set reached the bank.">
            <FormField label="Presented on" required :error="presentForm.errors.presented_on"><DateInput v-model="presentForm.presented_on" /></FormField>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Cancel</Button>
                <Button variant="primary" :loading="presentForm.processing" @click="post(presentForm, presenting)">Record presentation</Button>
            </template>
        </Modal>

        <Modal v-model:open="discrepancyOpen" title="Discrepancy" subtitle="What the bank found wrong, so the corrected set answers it.">
            <FormField label="Discrepancy" required :error="discrepancyForm.errors.reason"><textarea v-model="discrepancyForm.reason" rows="3" class="form-textarea" /></FormField>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Cancel</Button>
                <Button variant="danger" :disabled="!discrepancyForm.reason" :loading="discrepancyForm.processing" @click="post(discrepancyForm, discrepancyOpen)">Record discrepancy</Button>
            </template>
        </Modal>

        <Modal v-model:open="realising" title="Proceeds realised" subtitle="The bank's realisation reference is what the receipt and the export register quote.">
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField label="Realised on" required :error="realiseForm.errors.realised_on"><DateInput v-model="realiseForm.realised_on" /></FormField>
                <FormField label="Realisation reference" required :error="realiseForm.errors.realisation_ref"><TextInput v-model="realiseForm.realisation_ref" /></FormField>
            </div>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Cancel</Button>
                <Button variant="primary" :disabled="!realiseForm.realisation_ref" :loading="realiseForm.processing" @click="post(realiseForm, realising)">Record realisation</Button>
            </template>
        </Modal>

        <Modal v-model:open="cancelOpen" title="Cancel this credit" subtitle="Only while nothing has shipped against it.">
            <FormField label="Reason" required :error="cancelForm.errors.reason"><textarea v-model="cancelForm.reason" rows="2" class="form-textarea" /></FormField>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Keep it</Button>
                <Button variant="danger" :disabled="!cancelForm.reason" :loading="cancelForm.processing" @click="post(cancelForm, cancelOpen)">Cancel credit</Button>
            </template>
        </Modal>

        <Modal v-model:open="amending" width="max-w-2xl" title="Record an amendment" subtitle="Value, dates and what the bank charged for the change.">
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField label="Amended on" required :error="amendForm.errors.amended_on"><DateInput v-model="amendForm.amended_on" /></FormField>
                <FormField label="Value change" hint="Negative to reduce the credit." :error="amendForm.errors.amount_delta"><TextInput v-model="amendForm.amount_delta" type="number" step="0.0001" numeric /></FormField>
                <FormField label="New last shipment date" :error="amendForm.errors.new_last_shipment_date"><DateInput v-model="amendForm.new_last_shipment_date" /></FormField>
                <FormField label="New expiry date" :error="amendForm.errors.new_expiry_date"><DateInput v-model="amendForm.new_expiry_date" /></FormField>
                <FormField label="Bank charges" :error="amendForm.errors.charges_amount"><TextInput v-model="amendForm.charges_amount" type="number" step="0.0001" numeric /></FormField>
                <FormField label="Reason" class="sm:col-span-2" :error="amendForm.errors.narrative"><TextInput v-model="amendForm.narrative" /></FormField>
            </div>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Cancel</Button>
                <Button variant="primary" :loading="amendForm.processing" @click="amendForm.post(`${base}/amend`, { preserveScroll: true, onSuccess: () => { amending = false; amendForm.reset(); } })">Record amendment</Button>
            </template>
        </Modal>

        <Modal v-model:open="attaching" title="Attach a sales order" subtitle="Only this customer's orders in the credit's currency can be covered.">
            <div class="space-y-3">
                <FormField label="Sales order" required :error="attachForm.errors.sales_order_id">
                    <SelectInput v-model="attachForm.sales_order_id" :options="availableSalesOrders.map((so) => ({ value: so.id, label: `${so.number ?? '(draft)'} · ${money(so.total, letter.currency)}` }))" />
                </FormField>
                <FormField label="Covered amount" hint="Defaults to the order value." :error="attachForm.errors.covered_amount"><TextInput v-model="attachForm.covered_amount" type="number" step="0.0001" numeric /></FormField>
            </div>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Cancel</Button>
                <Button variant="primary" :loading="attachForm.processing" @click="attachForm.post(`${base}/orders`, { preserveScroll: true, onSuccess: () => { attaching = false; attachForm.reset(); } })">Attach</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
