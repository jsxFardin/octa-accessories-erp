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
import Icon from '@/Components/Ui/Icon.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, money, pcs, qty, titleCase, todayIso } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useGuardedAction } from '@/composables/useGuardedAction';

const props = defineProps({
    shipment: { type: Object, required: true },
    challans: { type: Array, default: () => [] },
    attachable: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
    /** `[{ type, label, present, required }]` — what the shipment needs and what it has. */
    checklist: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
    trail: { type: Array, default: () => [] },
});

const base = `/export-shipments/${props.shipment.id}`;
const name = computed(() => props.shipment.number ?? 'this shipment');
const canMove = (to) => props.availableTransitions.includes(to);
const editable = computed(() => ['draft', 'booked'].includes(props.shipment.status));
const open = computed(() => !['closed', 'cancelled'].includes(props.shipment.status));

const { busy, run } = useGuardedAction();

function move(status, confirm) {
    run(status, confirm, (done) => router.post(`${base}/transition`, { status }, { preserveScroll: true, ...done }));
}

const shipOpen = ref(false);
const shipForm = useForm({ status: 'shipped', shipped_on: todayIso() });
const presentOpen = ref(false);
const presentForm = useForm({ status: 'presented', presented_on: todayIso() });
const deliverOpen = ref(false);
const deliverForm = useForm({ status: 'delivered', delivered_on: todayIso() });
const cancelOpen = ref(false);
const cancelForm = useForm({ status: 'cancelled', reason: '' });
const post = (form, flag) => form.post(`${base}/transition`, { preserveScroll: true, onSuccess: () => { flag.value = false; } });

const attaching = ref(false);
const attachForm = useForm({ challan_id: '' });
function detach(challan) {
    run(`detach-${challan.id}`, { title: `Remove ${challan.number} from the shipment?`, message: 'The delivery note can be put on another shipment.', confirmLabel: 'Remove', tone: 'danger' },
        (done) => router.delete(`${base}/challans/${challan.id}`, { preserveScroll: true, ...done }));
}

const addingDocument = ref(false);
const documentForm = useForm({ doc_type: '', doc_no: '', doc_date: todayIso(), status: 'issued', remarks: '', file: null });
function submitDocument() {
    documentForm.post(`${base}/documents`, { preserveScroll: true, forceFormData: true, onSuccess: () => { addingDocument.value = false; documentForm.reset(); documentForm.doc_date = todayIso(); } });
}
function openDocument(type = '') {
    documentForm.reset();
    documentForm.doc_type = type;
    documentForm.doc_date = todayIso();
    addingDocument.value = true;
}
function removeDocument(doc) {
    run(`doc-${doc.id}`, { title: `Remove ${doc.label} ${doc.doc_no}?`, message: 'It can be recorded again.', confirmLabel: 'Remove', tone: 'danger' },
        (done) => router.delete(`${base}/documents/${doc.id}`, { preserveScroll: true, ...done }));
}
const markDocument = (doc, status) => router.put(`${base}/documents/${doc.id}`, { status }, { preserveScroll: true });

const missing = computed(() => props.checklist.filter((row) => row.required && !row.present));
const totalCartons = computed(() => props.challans.reduce((sum, c) => sum + Number(c.total_cartons ?? 0), 0));
const totalQty = computed(() => props.challans.reduce((sum, c) => sum + Number(c.total_qty ?? 0), 0));
const STATUS_LABEL = { drafted: 'Drafted', issued: 'Issued', submitted: 'Submitted to bank' };
</script>

<template>
    <AppLayout :crumb="shipment.number ?? 'Draft shipment'">
        <Head :title="shipment.number ?? 'Export shipment'" />

        <template #title>{{ shipment.number ?? '(draft)' }}</template>
        <template #subtitle>
            <Link v-if="shipment.customer" :href="`/customers/${shipment.customer.id}`" class="doc-link">{{ shipment.customer.name }}</Link>
            · {{ titleCase(shipment.mode) }}
            <template v-if="shipment.letter_of_credit"> · under <Link :href="`/export-lcs/${shipment.letter_of_credit.id}`" class="doc-link">{{ shipment.letter_of_credit.number }}</Link></template>
        </template>

        <template #actions>
            <Badge :status="shipment.status" />
            <Button v-if="canMove('booked') && can('export_shipment.update')" size="sm" variant="primary" :loading="busy === 'booked'" @click="move('booked', { title: `Book ${name}?`, message: 'The shipment is given its number and its space is treated as booked with the forwarder.', confirmLabel: 'Book shipment' })">Book</Button>
            <Button v-if="canMove('shipped') && can('export_shipment.ship')" size="sm" variant="primary" @click="shipOpen = true">Mark shipped</Button>
            <Button v-if="canMove('presented') && can('export_shipment.update')" size="sm" variant="primary" @click="presentOpen = true">Documents presented</Button>
            <Button v-if="canMove('delivered') && can('export_shipment.update')" size="sm" :variant="shipment.letter_of_credit ? 'secondary' : 'primary'" @click="deliverOpen = true">Mark delivered</Button>
            <Button v-if="canMove('closed') && can('export_shipment.close')" size="sm" variant="primary" :loading="busy === 'closed'" :disabled="missing.length > 0" :title="missing.length ? `Still missing: ${missing.map((m) => m.label).join(', ')}` : null" @click="move('closed', { title: `Close ${name}?`, message: 'Every document is on file and nothing more is recorded against it. This cannot be undone.', confirmLabel: 'Close shipment', tone: 'danger' })">Close</Button>
            <Button v-if="open && can('export_shipment.update')" size="sm" :href="`${base}/edit`">Edit</Button>
            <Button v-if="canMove('cancelled') && can('export_shipment.update')" size="sm" variant="danger" @click="cancelOpen = true">Cancel</Button>
            <ActivityTrail :entries="trail" />
        </template>

        <div class="space-y-4">
            <div v-if="shipment.letter_of_credit && shipment.status === 'shipped'" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
                Shipped {{ date(shipment.shipped_on) }} under {{ shipment.letter_of_credit.number }}. The documents must reach the bank within {{ shipment.letter_of_credit.latest_presentation_days }} days; record the presentation once they have.
            </div>

            <Card title="Consignment">
                <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-ink-500">Forwarder</dt><dd class="font-medium">{{ shipment.forwarder ?? '—' }}</dd></div>
                    <div><dt class="text-ink-500">Carrier · vessel/flight</dt><dd class="font-medium">{{ [shipment.carrier, shipment.vessel_or_flight].filter(Boolean).join(' · ') || '—' }}</dd></div>
                    <div><dt class="text-ink-500">Container · seal</dt><dd class="font-medium">{{ [shipment.container_no, shipment.seal_no].filter(Boolean).join(' · ') || '—' }}</dd></div>
                    <div><dt class="text-ink-500">Terms</dt><dd class="font-medium">{{ [shipment.incoterm, shipment.port_of_loading, shipment.port_of_discharge, shipment.final_destination].filter(Boolean).join(' · ') || '—' }}</dd></div>
                    <div><dt class="text-ink-500">ETD · ETA</dt><dd class="font-medium">{{ shipment.etd ? date(shipment.etd) : '—' }} · {{ shipment.eta ? date(shipment.eta) : '—' }}</dd></div>
                    <div><dt class="text-ink-500">Shipped · delivered</dt><dd class="font-medium">{{ shipment.shipped_on ? date(shipment.shipped_on) : '—' }} · {{ shipment.delivered_on ? date(shipment.delivered_on) : '—' }}</dd></div>
                    <div><dt class="text-ink-500">BL / AWB</dt><dd class="font-mono text-xs">{{ shipment.transport_doc_no ?? '—' }}<span v-if="shipment.transport_doc_date" class="font-sans text-ink-500"> · {{ date(shipment.transport_doc_date) }}</span></dd></div>
                    <div><dt class="text-ink-500">EXP · UD</dt><dd class="font-mono text-xs">{{ [shipment.exp_form_no, shipment.ud_no].filter(Boolean).join(' · ') || '—' }}</dd></div>
                    <div><dt class="text-ink-500">Goods value</dt><dd class="tnum font-medium">{{ money(shipment.goods_value, shipment.currency) }}</dd></div>
                    <div><dt class="text-ink-500">Weight · volume</dt><dd class="tnum font-medium">{{ shipment.gross_kg ? `${qty(shipment.gross_kg)} kg gross` : '—' }}<template v-if="shipment.net_kg"> · {{ qty(shipment.net_kg) }} kg net</template><template v-if="shipment.cbm"> · {{ qty(shipment.cbm) }} CBM</template></dd></div>
                    <div><dt class="text-ink-500">Cartons · pieces</dt><dd class="tnum font-medium">{{ pcs(totalCartons) }} · {{ pcs(totalQty) }}</dd></div>
                    <div><dt class="text-ink-500">Opened by</dt><dd class="font-medium">{{ shipment.raised_by ?? '—' }}</dd></div>
                </dl>
                <p v-if="shipment.remarks" class="mt-3 text-sm text-ink-600">{{ shipment.remarks }}</p>
            </Card>

            <Card title="Delivery notes on this shipment" :padded="false">
                <template #actions>
                    <Button v-if="editable && attachable.length && can('export_shipment.update')" size="sm" @click="attaching = true">Attach delivery note</Button>
                </template>
                <DataTable
                    :columns="[{ key: 'number', label: 'Delivery note' }, { key: 'order_number', label: 'Order' }, { key: 'challan_date', label: 'Date' }, { key: 'total_cartons', label: 'Cartons', align: 'right' }, { key: 'total_qty', label: 'Pieces', align: 'right' }, { key: 'status', label: 'Status' }, { key: 'actions', label: '', align: 'right' }]"
                    :rows="challans" row-key="id" empty="No delivery notes yet. A shipment is booked with the notes it carries." dense
                >
                    <template #cell:number="{ row, value }"><Link :href="`/delivery-challans/${row.id}`" class="doc-link-quiet">{{ value }}</Link></template>
                    <template #cell:order_number="{ row, value }"><Link v-if="row.order_id" :href="`/sales-orders/${row.order_id}`" class="doc-link-quiet">{{ value }}</Link><span v-else>—</span></template>
                    <template #cell:challan_date="{ value }">{{ date(value) }}</template>
                    <template #cell:total_cartons="{ value }">{{ pcs(value) }}</template>
                    <template #cell:total_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                    <template #cell:actions="{ row }">
                        <button v-if="editable && can('export_shipment.update')" type="button" class="rounded px-1.5 py-1 text-xs text-rose-700 hover:underline disabled:opacity-50" :disabled="busy !== null" @click.stop="detach(row)">Remove</button>
                    </template>
                </DataTable>
            </Card>

            <div class="grid gap-4 lg:grid-cols-5">
                <!-- DF-6 AC3 — the checklist: what this shipment needs, derived from mode, credit, buyer and terms. -->
                <Card class="lg:col-span-2" title="Document checklist" :subtitle="missing.length ? `${missing.length} still missing before the shipment is complete` : 'Complete'">
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="row in checklist" :key="row.type" class="flex items-center justify-between gap-3 py-2">
                            <span class="flex items-center gap-2">
                                <Icon :name="row.present ? 'check' : 'close'" size="size-4" :class="row.present ? 'text-emerald-600' : (row.required ? 'text-rose-600' : 'text-ink-300')" />
                                <span :class="row.present ? 'text-ink-800' : (row.required ? 'font-medium text-ink-900' : 'text-ink-500')">{{ row.label }}</span>
                                <span v-if="!row.required" class="text-xs text-ink-400">optional</span>
                            </span>
                            <Button v-if="!row.present && open && can('export_document.create')" size="xs" @click="openDocument(row.type)">Record</Button>
                        </li>
                    </ul>
                </Card>

                <Card class="lg:col-span-3" title="Documents" :padded="false">
                    <template #actions>
                        <Button v-if="open && can('export_document.create')" size="sm" @click="openDocument()">Add document</Button>
                    </template>
                    <DataTable
                        :columns="[{ key: 'label', label: 'Document' }, { key: 'doc_no', label: 'Number' }, { key: 'doc_date', label: 'Date' }, { key: 'status', label: 'Status' }, { key: 'actions', label: '', align: 'right' }]"
                        :rows="documents" row-key="id" empty="Nothing recorded yet." dense
                    >
                        <template #cell:doc_no="{ row, value }"><span class="font-mono text-xs">{{ value }}</span><span v-if="row.remarks" class="block text-xs text-ink-500">{{ row.remarks }}</span></template>
                        <template #cell:doc_date="{ value }">{{ value ? date(value) : '—' }}</template>
                        <template #cell:status="{ row, value }">
                            <Badge :status="value" :label="STATUS_LABEL[value] ?? titleCase(value)" />
                            <span v-if="row.has_file" class="ml-1 text-xs text-ink-500">· file</span>
                        </template>
                        <template #cell:actions="{ row }">
                            <div class="flex justify-end gap-1">
                                <Button v-if="row.status !== 'submitted' && open && can('export_document.update')" size="xs" @click="markDocument(row, 'submitted')">Submitted</Button>
                                <button v-if="shipment.status !== 'closed' && can('export_document.delete')" type="button" class="rounded px-1.5 py-1 text-xs text-rose-700 hover:underline disabled:opacity-50" :disabled="busy !== null" @click.stop="removeDocument(row)">Remove</button>
                            </div>
                        </template>
                    </DataTable>
                </Card>
            </div>

            <Card v-if="invoices.length" title="Invoices for the goods on this shipment" :padded="false">
                <DataTable :columns="[{ key: 'number', label: 'Invoice' }, { key: 'total', label: 'Total', align: 'right' }, { key: 'status', label: 'Status' }]" :rows="invoices" row-key="id" :row-href="(row) => `/invoices/${row.id}`" dense>
                    <template #cell:number="{ value }">{{ value ?? '(draft)' }}</template>
                    <template #cell:total="{ value }">{{ money(value, shipment.currency) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>

        <Modal v-model:open="shipOpen" title="Mark shipped" subtitle="The date on the transport document. A credit the shipment is under moves to shipped with it.">
            <FormField label="Shipped on" required :error="shipForm.errors.shipped_on"><DateInput v-model="shipForm.shipped_on" /></FormField>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Cancel</Button>
                <Button variant="primary" :loading="shipForm.processing" @click="post(shipForm, shipOpen)">Mark shipped</Button>
            </template>
        </Modal>
        <Modal v-model:open="presentOpen" title="Documents presented" subtitle="The date the full set reached the bank.">
            <FormField label="Presented on" required :error="presentForm.errors.presented_on"><DateInput v-model="presentForm.presented_on" /></FormField>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Cancel</Button>
                <Button variant="primary" :loading="presentForm.processing" @click="post(presentForm, presentOpen)">Record presentation</Button>
            </template>
        </Modal>
        <Modal v-model:open="deliverOpen" title="Mark delivered" subtitle="The goods reached the customer.">
            <FormField label="Delivered on" required :error="deliverForm.errors.delivered_on"><DateInput v-model="deliverForm.delivered_on" /></FormField>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Cancel</Button>
                <Button variant="primary" :loading="deliverForm.processing" @click="post(deliverForm, deliverOpen)">Mark delivered</Button>
            </template>
        </Modal>
        <Modal v-model:open="cancelOpen" title="Cancel this shipment" subtitle="Its delivery notes are released to another shipment.">
            <FormField label="Reason" required :error="cancelForm.errors.reason"><textarea v-model="cancelForm.reason" rows="2" class="form-textarea" /></FormField>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Keep it</Button>
                <Button variant="danger" :disabled="!cancelForm.reason" :loading="cancelForm.processing" @click="post(cancelForm, cancelOpen)">Cancel shipment</Button>
            </template>
        </Modal>
        <Modal v-model:open="attaching" title="Attach a delivery note" subtitle="This customer's issued notes that are not on another shipment.">
            <FormField label="Delivery note" required :error="attachForm.errors.challan_id">
                <SelectInput v-model="attachForm.challan_id" placeholder="— select —" :options="attachable.map((c) => ({ value: c.id, label: `${c.number} · ${date(c.challan_date)} · ${pcs(c.total_cartons)} ctn` }))" />
            </FormField>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Cancel</Button>
                <Button variant="primary" :loading="attachForm.processing" :disabled="!attachForm.challan_id" @click="attachForm.post(`${base}/challans`, { preserveScroll: true, onSuccess: () => { attaching = false; attachForm.reset(); } })">Attach</Button>
            </template>
        </Modal>
        <Modal v-model:open="addingDocument" title="Record a document" subtitle="Number and date as printed; the file is optional.">
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField label="Document" required :error="documentForm.errors.doc_type"><SelectInput v-model="documentForm.doc_type" placeholder="— select —" :options="documentTypes" /></FormField>
                <FormField label="Number" required :error="documentForm.errors.doc_no"><TextInput v-model="documentForm.doc_no" /></FormField>
                <FormField label="Date" :error="documentForm.errors.doc_date"><DateInput v-model="documentForm.doc_date" /></FormField>
                <FormField label="Status" :error="documentForm.errors.status"><SelectInput v-model="documentForm.status" :options="[{ value: 'drafted', label: 'Drafted' }, { value: 'issued', label: 'Issued' }, { value: 'submitted', label: 'Submitted to bank' }]" :placeholder="null" /></FormField>
                <FormField label="File" :error="documentForm.errors.file" hint="PDF or image, up to 10 MB."><input type="file" accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-sm" @change="documentForm.file = $event.target.files[0] ?? null" /></FormField>
                <FormField label="Remarks" :error="documentForm.errors.remarks"><TextInput v-model="documentForm.remarks" /></FormField>
            </div>
            <template #footer="{ close: dismiss }">
                <Button @click="dismiss">Cancel</Button>
                <Button variant="primary" :loading="documentForm.processing" :disabled="!documentForm.doc_type || !documentForm.doc_no" @click="submitDocument">Record</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
