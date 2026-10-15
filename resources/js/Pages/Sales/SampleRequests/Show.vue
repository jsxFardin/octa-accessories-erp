<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
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
import { date, money, pcs, titleCase, todayIso } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';

const props = defineProps({
    sampleRequest: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    dispatches: { type: Array, default: () => [] },
    factoryUnits: { type: Array, default: () => [] },
    decisions: { type: Array, default: () => [] },
    availableTransitions: { type: Array, default: () => [] },
    trail: { type: Array, default: () => [] },
});

const base = computed(() => `/sample-requests/${props.sampleRequest.id}`);
const status = computed(() => props.sampleRequest.status);
const editable = computed(() => ['requested', 'in_development'].includes(status.value) && can('sample_request.update'));
const open = computed(() => !['dispatched', 'approved', 'rejected', 'cancelled'].includes(status.value));
const canMove = (to) => props.availableTransitions.includes(to);

const confirmTransition = useTransitionConfirm('sample_request');

async function transition(to) {
    if (!(await confirmTransition(to, props.sampleRequest.number))) return;

    router.post(`${base.value}/transition`, { status: to }, { preserveScroll: true });
}

/* Cancel asks why. */
const cancelOpen = ref(false);
const cancelForm = useForm({ status: 'cancelled', reason: '' });
const submitCancel = () => cancelForm.post(`${base.value}/transition`, { preserveScroll: true, onSuccess: () => { cancelOpen.value = false; cancelForm.reset(); } });

/* AS-6 — the parcel. */
const dispatchOpen = ref(false);
const dispatchForm = useForm({ dispatched_on: todayIso(), courier_name: '', tracking_no: '', recipient: '', remarks: '' });
const submitDispatch = () => dispatchForm.post(`${base.value}/dispatch`, { preserveScroll: true, onSuccess: () => { dispatchOpen.value = false; dispatchForm.reset(); } });

/* AS-6 AC3 — delivered later. */
const deliveredForm = useForm({ delivered_on: todayIso() });
const deliveredFor = ref(null);
const submitDelivered = (dispatch) => deliveredForm.put(`${base.value}/dispatches/${dispatch.id}`, { preserveScroll: true, onSuccess: () => { deliveredFor.value = null; } });

/* AS-5 AC4 — the sample run from a line. */
const jobCardLine = ref(null);
const jobCardForm = useForm({ factory_unit_id: '', planned_qty: '', due_date: '', priority: 50 });

function openJobCard(line) {
    jobCardLine.value = line;
    jobCardForm.reset();
    jobCardForm.planned_qty = String(Number(line.qty));
    jobCardForm.due_date = props.sampleRequest.required_by ?? '';
    if (props.factoryUnits.length === 1) jobCardForm.factory_unit_id = props.factoryUnits[0].id;
}

const submitJobCard = () => jobCardForm.post(`${base.value}/lines/${jobCardLine.value.id}/job-card`, { preserveScroll: true });

/* AS-7 — the customer's decision on a line. */
const decisionLine = ref(null);
const decisionForm = useForm({ decision: '', decided_on: todayIso(), customer_ref: '', comments: '' });

function openDecision(line) {
    decisionLine.value = line;
    decisionForm.reset();
    decisionForm.decided_on = todayIso();
}

const submitDecision = () => decisionForm.post(`${base.value}/lines/${decisionLine.value.id}/decision`, { preserveScroll: true, onSuccess: () => { decisionLine.value = null; } });

const decisionOptions = computed(() => props.decisions.map((d) => ({ value: d, label: titleCase(d) })));
const commentsRequired = computed(() => decisionForm.decision && decisionForm.decision !== 'approved');

const canRaiseJobCard = (line) => open.value && can('job_card.create') && line.product_id && ['pending'].includes(line.status);
const canDecide = (line) => status.value === 'dispatched' && can('sample_request.update') && !['approved', 'rejected'].includes(line.status);

const lineColumns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'description', label: 'Sample' },
    { key: 'product', label: 'Product' },
    { key: 'artwork_version', label: 'Artwork' },
    { key: 'colourway', label: 'Colourway' },
    { key: 'qty', label: 'Qty', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'job_cards', label: 'Job card' },
    { key: 'decision', label: 'Decision' },
    { key: 'actions', label: '', align: 'right' },
];

const latestDecision = (line) => line.decisions[line.decisions.length - 1] ?? null;
const totalQty = computed(() => props.lines.reduce((sum, line) => sum + Number(line.qty), 0));
</script>

<template>
    <AppLayout :crumb="sampleRequest.number">
        <Head :title="sampleRequest.number" />

        <template #title>{{ sampleRequest.number }}</template>
        <template #subtitle>
            <Link v-if="sampleRequest.customer" :href="`/customers/${sampleRequest.customer.id}`" class="doc-link">{{ sampleRequest.customer.name }}</Link>
            · {{ titleCase(sampleRequest.sample_type) }} sample · requested {{ date(sampleRequest.requested_on) }}
        </template>

        <template #actions>
            <Badge :status="status" />
            <Button v-if="canMove('in_development') && can('sample_request.update')" size="sm" variant="primary" @click="transition('in_development')">Start development</Button>
            <Button v-if="canMove('ready') && can('sample_request.update')" size="sm" :variant="status === 'in_production' ? 'primary' : 'default'" @click="transition('ready')">Mark ready</Button>
            <Button v-if="status === 'ready' && can('sample_request.dispatch')" size="sm" variant="primary" @click="dispatchOpen = true">Dispatch</Button>
            <Button v-if="editable" size="sm" :href="`${base}/edit`">Edit</Button>
            <Button v-if="canMove('cancelled') && can('sample_request.update')" size="sm" variant="danger" @click="cancelOpen = true">Cancel request</Button>
            <ActivityTrail :entries="trail" />
        </template>

        <div class="space-y-4">
            <div v-if="status === 'dispatched'" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
                With the customer since {{ date(dispatches[dispatches.length - 1]?.dispatched_on) }}. Record their decision on each line below; the request is approved or rejected once every line is.
            </div>

            <Card>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
                    <div>
                        <dt class="text-xs text-ink-500">Customer</dt>
                        <dd class="mt-0.5 font-medium text-ink-900"><Link v-if="sampleRequest.customer" :href="`/customers/${sampleRequest.customer.id}`" class="doc-link-quiet">{{ sampleRequest.customer.name }}</Link></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Type</dt>
                        <dd class="mt-0.5 text-ink-900">{{ titleCase(sampleRequest.sample_type) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Linked to</dt>
                        <dd class="mt-0.5" :class="sampleRequest.inquiry || sampleRequest.sales_order ? 'text-ink-900' : 'text-ink-400'">
                            <Link v-if="sampleRequest.inquiry" :href="`/inquiries/${sampleRequest.inquiry.id}`" class="doc-link-quiet">{{ sampleRequest.inquiry.number }}</Link>
                            <template v-if="sampleRequest.inquiry && sampleRequest.sales_order"> · </template>
                            <Link v-if="sampleRequest.sales_order" :href="`/sales-orders/${sampleRequest.sales_order.id}`" class="doc-link-quiet">{{ sampleRequest.sales_order.number }}</Link>
                            <template v-if="!sampleRequest.inquiry && !sampleRequest.sales_order">Nothing yet</template>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Required by</dt>
                        <dd class="mt-0.5" :class="sampleRequest.required_by ? 'text-ink-900' : 'text-ink-400'">{{ sampleRequest.required_by ? date(sampleRequest.required_by) : 'Open' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Charge</dt>
                        <dd class="mt-0.5 tnum" :class="sampleRequest.is_chargeable ? 'font-medium text-ink-900' : 'text-ink-400'">
                            {{ sampleRequest.is_chargeable ? money(sampleRequest.charge_amount) : 'Free of charge' }}
                            <span v-if="sampleRequest.is_chargeable && status === 'approved' && !sampleRequest.charge_invoiced" class="block text-xs font-normal text-amber-700">Not invoiced yet</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Raised by</dt>
                        <dd class="mt-0.5 text-ink-900">{{ sampleRequest.raised_by ?? '—' }}</dd>
                    </div>
                </dl>
                <p v-if="sampleRequest.notes" class="mt-3 border-t border-slate-100 pt-3 text-sm whitespace-pre-line text-ink-700">{{ sampleRequest.notes }}</p>
            </Card>

            <Card title="Lines" :subtitle="`${pcs(lines.length)} ${lines.length === 1 ? 'sample' : 'samples'} · ${pcs(totalQty)} pcs`" :padded="false">
                <DataTable :columns="lineColumns" :rows="lines" row-key="id" empty="No lines." dense>
                    <template #cell:product="{ row }">
                        <Link v-if="row.product" :href="`/products/${row.product.id}`" class="doc-link-quiet">{{ row.product.code }}</Link>
                        <span v-else class="text-ink-400">not yet a product</span>
                    </template>
                    <template #cell:artwork_version="{ value }">
                        <template v-if="value">v{{ value.version_no }} <Badge :status="value.status" /></template>
                        <span v-else class="text-ink-400">—</span>
                    </template>
                    <template #cell:colourway="{ value }"><span :class="value ? '' : 'text-ink-400'">{{ value ?? '—' }}</span></template>
                    <template #cell:qty="{ value }"><span class="tnum">{{ pcs(value) }}</span></template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                    <template #cell:job_cards="{ value }">
                        <template v-if="value.length">
                            <Link v-for="card in value" :key="card.id" :href="`/job-cards/${card.id}`" class="doc-link-quiet block">{{ card.number ?? `#${card.id}` }} <span class="text-xs text-ink-500">· {{ titleCase(card.status) }}</span></Link>
                        </template>
                        <span v-else class="text-ink-400">—</span>
                    </template>
                    <template #cell:decision="{ row }">
                        <template v-if="latestDecision(row)">
                            <span class="font-medium" :class="latestDecision(row).decision === 'rejected' ? 'text-rose-700' : 'text-emerald-700'">{{ titleCase(latestDecision(row).decision) }}</span>
                            <span class="block text-xs text-ink-500">{{ date(latestDecision(row).decided_on) }}<template v-if="latestDecision(row).customer_ref"> · {{ latestDecision(row).customer_ref }}</template></span>
                            <span v-if="latestDecision(row).comments" class="block max-w-xs text-xs text-ink-700">{{ latestDecision(row).comments }}</span>
                        </template>
                        <span v-else class="text-ink-400">—</span>
                    </template>
                    <template #cell:actions="{ row }">
                        <div class="flex justify-end gap-1">
                            <Button v-if="canRaiseJobCard(row)" size="xs" @click="openJobCard(row)">Raise job card</Button>
                            <Button v-if="canDecide(row)" size="xs" variant="primary" @click="openDecision(row)">Record decision</Button>
                        </div>
                    </template>
                </DataTable>
            </Card>

            <Card title="Dispatches" :padded="dispatches.length === 0">
                <p v-if="dispatches.length === 0" class="text-sm text-ink-600">
                    <template v-if="status === 'ready'">The pieces are ready. Dispatch records the courier, the tracking number and who it is addressed to.</template>
                    <template v-else>Nothing sent yet. A request is dispatched once it is ready.</template>
                </p>
                <table v-else class="w-full text-sm">
                    <thead class="text-left text-xs text-ink-500">
                        <tr><th class="px-4 py-2">Sent</th><th class="px-4 py-2">Courier</th><th class="px-4 py-2">Tracking</th><th class="px-4 py-2">Recipient</th><th class="px-4 py-2">Delivered</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="dispatch in dispatches" :key="dispatch.id" class="border-t border-slate-100">
                            <td class="px-4 py-2">{{ date(dispatch.dispatched_on) }}</td>
                            <td class="px-4 py-2">{{ dispatch.courier_name }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ dispatch.tracking_no ?? '—' }}</td>
                            <td class="px-4 py-2">{{ dispatch.recipient }}<span v-if="dispatch.remarks" class="block text-xs text-ink-500">{{ dispatch.remarks }}</span></td>
                            <td class="px-4 py-2">
                                <template v-if="dispatch.delivered_on">{{ date(dispatch.delivered_on) }}</template>
                                <template v-else-if="deliveredFor === dispatch.id">
                                    <form class="flex items-end gap-2" @submit.prevent="submitDelivered(dispatch)">
                                        <FormField label="Delivered on" :error="deliveredForm.errors.delivered_on"><DateInput v-model="deliveredForm.delivered_on" :min="dispatch.dispatched_on" /></FormField>
                                        <Button type="submit" size="xs" variant="primary" :loading="deliveredForm.processing">Save</Button>
                                        <Button size="xs" @click="deliveredFor = null">Cancel</Button>
                                    </form>
                                </template>
                                <Button v-else-if="can('sample_request.dispatch')" size="xs" @click="deliveredFor = dispatch.id">Mark delivered</Button>
                                <span v-else class="text-ink-400">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>

        <Modal v-model:open="cancelOpen" title="Cancel this sample request" subtitle="The merchandiser who raised it will read the reason.">
            <FormField label="Reason" :error="cancelForm.errors.reason" required>
                <textarea v-model="cancelForm.reason" rows="3" class="form-textarea" />
            </FormField>
            <template #footer="{ close }">
                <Button @click="close">Keep it</Button>
                <Button variant="danger" :loading="cancelForm.processing" :disabled="!cancelForm.reason" @click="submitCancel">Cancel request</Button>
            </template>
        </Modal>

        <Modal v-model:open="dispatchOpen" title="Dispatch the samples" subtitle="Courier, tracking number and recipient. The tracking number goes on the follow-up list.">
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField label="Sent on" :error="dispatchForm.errors.dispatched_on" required><DateInput v-model="dispatchForm.dispatched_on" /></FormField>
                <FormField label="Courier" :error="dispatchForm.errors.courier_name" required><TextInput v-model="dispatchForm.courier_name" placeholder="DHL, Sundarban, by hand…" /></FormField>
                <FormField label="Tracking number" :error="dispatchForm.errors.tracking_no"><TextInput v-model="dispatchForm.tracking_no" /></FormField>
                <FormField label="Recipient" :error="dispatchForm.errors.recipient" required><TextInput v-model="dispatchForm.recipient" placeholder="Name at the customer" /></FormField>
                <FormField class="sm:col-span-2" label="Remarks" :error="dispatchForm.errors.remarks"><TextInput v-model="dispatchForm.remarks" /></FormField>
            </div>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="dispatchForm.processing" @click="submitDispatch">Dispatch</Button>
            </template>
        </Modal>

        <Modal :open="jobCardLine !== null" title="Raise a sample job card" :subtitle="jobCardLine ? `Line ${jobCardLine.line_no} · ${jobCardLine.description}` : null" @update:open="(v) => { if (!v) jobCardLine = null; }">
            <p class="mb-3 text-sm text-ink-600">The sample is made like any other run: it draws real material and is costed when it closes.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField label="Factory unit" :error="jobCardForm.errors.factory_unit_id" required>
                    <SelectInput v-model="jobCardForm.factory_unit_id" placeholder="— select —" :options="factoryUnits" value-key="id" label-key="name" hint-key="code" />
                </FormField>
                <FormField label="Quantity (pcs)" :error="jobCardForm.errors.planned_qty" required><TextInput v-model="jobCardForm.planned_qty" type="number" numeric min="1" /></FormField>
                <FormField label="Due" :error="jobCardForm.errors.due_date"><DateInput v-model="jobCardForm.due_date" /></FormField>
                <FormField label="Priority" :error="jobCardForm.errors.priority" hint="1 is most urgent; 50 is the default."><TextInput v-model="jobCardForm.priority" type="number" numeric min="1" max="99" /></FormField>
            </div>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="jobCardForm.processing" @click="submitJobCard">Raise job card</Button>
            </template>
        </Modal>

        <Modal :open="decisionLine !== null" title="Record the customer's decision" :subtitle="decisionLine ? `Line ${decisionLine.line_no} · ${decisionLine.description}` : null" @update:open="(v) => { if (!v) decisionLine = null; }">
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField label="Decision" :error="decisionForm.errors.decision" required><SelectInput v-model="decisionForm.decision" placeholder="— select —" :options="decisionOptions" /></FormField>
                <FormField label="Decided on" :error="decisionForm.errors.decided_on" required><DateInput v-model="decisionForm.decided_on" /></FormField>
                <FormField class="sm:col-span-2" label="Customer reference" :error="decisionForm.errors.customer_ref" hint="Their email subject, approval sheet number or the name who signed."><TextInput v-model="decisionForm.customer_ref" /></FormField>
                <FormField class="sm:col-span-2" label="Comments" :error="decisionForm.errors.comments" :required="commentsRequired" :hint="commentsRequired ? 'What they want changed; the next sample or the bulk run is corrected from this.' : null">
                    <textarea v-model="decisionForm.comments" rows="3" class="form-textarea" />
                </FormField>
            </div>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="decisionForm.processing" :disabled="!decisionForm.decision" @click="submitDecision">Record</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
