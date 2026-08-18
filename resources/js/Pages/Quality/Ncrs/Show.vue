<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import { date, datetime, pcs, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    ncr: { type: Object, required: true },
    inspection: { type: Object, default: null },
    jobCard: { type: Object, default: null },
    operation: { type: Object, default: null },
    product: { type: Object, default: null },
    salesOrder: { type: Object, default: null },
    capas: { type: Array, default: () => [] },
    pendingAction: { type: Object, default: null },
    availableTransitions: { type: Array, default: () => [] },
    owners: { type: Array, default: () => [] },
    audit: { type: Array, default: () => [] },
});

const assignOpen = ref(false);
const investigateOpen = ref(false);
const verifyOpen = ref(false);

const assignForm = useForm({ owner_id: props.ncr.owner?.id ?? null });
const investigateForm = useForm({
    root_cause: '',
    action: '',
    preventive_action: '',
    due_date: null,
    responsible_id: props.ncr.owner?.id ?? null,
});
const verifyForm = useForm({ effectiveness: 'effective' });
const dispositionForm = useForm({});
const closeForm = useForm({});

const corrective = computed(() => props.capas.find((row) => row.kind === 'corrective') ?? null);
const preventive = computed(() => props.capas.find((row) => row.kind === 'preventive') ?? null);

function assign() {
    assignForm.post(`/ncrs/${props.ncr.id}/assign`, {
        preserveScroll: true,
        onSuccess: () => {
            assignOpen.value = false;
        },
    });
}

function investigate() {
    investigateForm.post(`/ncrs/${props.ncr.id}/investigate`, {
        preserveScroll: true,
        onSuccess: () => {
            investigateOpen.value = false;
            investigateForm.reset();
        },
    });
}

function disposition() {
    dispositionForm.post(`/ncrs/${props.ncr.id}/disposition`, { preserveScroll: true });
}

function verify() {
    verifyForm.post(`/ncrs/${props.ncr.id}/verify`, {
        preserveScroll: true,
        onSuccess: () => {
            verifyOpen.value = false;
        },
    });
}

function closeNcr() {
    closeForm.post(`/ncrs/${props.ncr.id}/close`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="ncr.number" />

        <template #title>{{ ncr.number }}</template>
        <template #subtitle>
            {{ titleCase(ncr.source) }} · {{ titleCase(ncr.severity) }} · raised {{ date(ncr.raised_on) }}
        </template>

        <template #actions>
            <Badge :status="ncr.status" />
            <Button v-if="can('ncr.update') && ncr.status !== 'closed'" size="sm" @click="assignOpen = true">
                Assign
            </Button>
            <Button
                v-if="availableTransitions.includes('investigating')"
                size="sm"
                variant="primary"
                @click="investigateOpen = true"
            >
                Investigate
            </Button>
            <Button
                v-if="availableTransitions.includes('action_taken')"
                size="sm"
                variant="primary"
                :loading="dispositionForm.processing"
                @click="disposition"
            >
                Record action taken
            </Button>
            <Button
                v-if="availableTransitions.includes('verified')"
                size="sm"
                variant="primary"
                @click="verifyOpen = true"
            >
                Verify
            </Button>
            <Button
                v-if="availableTransitions.includes('closed')"
                size="sm"
                variant="success"
                :loading="closeForm.processing"
                @click="closeNcr"
            >
                Close
            </Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card title="NCR" class="lg:col-span-2">
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-ink-500">Owner</dt>
                        <dd class="font-medium">{{ ncr.owner?.name ?? 'Unassigned' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Raised by</dt>
                        <dd class="font-medium">{{ ncr.raiser?.name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Closed on</dt>
                        <dd class="font-medium">{{ date(ncr.closed_on) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Severity</dt>
                        <dd>
                            <Badge
                                :tone="ncr.severity === 'critical' ? 'danger' : ncr.severity === 'major' ? 'warning' : 'neutral'"
                                :label="titleCase(ncr.severity)"
                            />
                        </dd>
                    </div>
                </dl>
                <p class="mt-4 whitespace-pre-line text-sm text-ink-700">{{ ncr.description }}</p>
            </Card>

            <Card title="Disposition" rule="BR-33 · P1-3" subtitle="Chosen at QC rejection. This screen does not re-apply it.">
                <div v-if="inspection?.disposition" class="space-y-2">
                    <Badge tone="warning" :label="titleCase(inspection.disposition)" />
                    <p class="text-sm text-ink-700">{{ pendingAction?.detail }}</p>
                    <Badge
                        v-if="pendingAction"
                        :tone="pendingAction.status === 'pending' ? 'danger' : 'success'"
                        :label="titleCase(pendingAction.status)"
                    />
                </div>
                <p v-else class="text-sm text-ink-500">No QC disposition attached.</p>
            </Card>

            <Card title="Source" class="lg:col-span-3">
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-ink-500">QC inspection</dt>
                        <dd>
                            <Link v-if="inspection" :href="`/qc-inspections/${inspection.id}`" class="font-medium text-brand-700">
                                {{ inspection.number }}
                            </Link>
                            <span v-else>—</span>
                            <Badge v-if="inspection" :status="inspection.result" class="ml-1" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Job card</dt>
                        <dd>
                            <Link v-if="jobCard" :href="`/job-cards/${jobCard.id}`" class="font-medium text-brand-700">
                                {{ jobCard.number }}
                            </Link>
                            <span v-else>—</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Operation</dt>
                        <dd class="font-medium">{{ operation ? `${operation.code} — ${operation.name}` : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Product</dt>
                        <dd>
                            <Link v-if="product" :href="`/products/${product.id}`" class="font-medium text-brand-700">
                                {{ product.code }}
                            </Link>
                            <span v-else>—</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Sales order</dt>
                        <dd>
                            <Link v-if="salesOrder" :href="`/sales-orders/${salesOrder.id}`" class="font-medium text-brand-700">
                                {{ salesOrder.number }}
                            </Link>
                            <span v-else>—</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Affected qty</dt>
                        <dd class="font-medium tnum">{{ inspection ? pcs(inspection.lot_size) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Critical / major</dt>
                        <dd class="font-medium tnum">
                            <span v-if="inspection">{{ inspection.critical_found }} / {{ inspection.major_found }}</span>
                            <span v-else>—</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Sample</dt>
                        <dd class="font-medium tnum">{{ inspection ? pcs(inspection.sample_size) : '—' }}</dd>
                    </div>
                </dl>
            </Card>

            <Card title="Investigation / CAPA" rule="QL-7" class="lg:col-span-3">
                <div v-if="!corrective" class="text-sm text-ink-500">
                    No CAPA recorded yet. Assign an owner, then record root cause and corrective action.
                </div>
                <div v-else class="grid gap-4 lg:grid-cols-2">
                    <div>
                        <h3 class="text-xs font-medium tracking-wide text-ink-500 uppercase">Corrective</h3>
                        <p class="mt-2 text-xs text-ink-500">Root cause</p>
                        <p class="whitespace-pre-line text-sm text-ink-800">{{ corrective.root_cause }}</p>
                        <p class="mt-2 text-xs text-ink-500">Action</p>
                        <p class="whitespace-pre-line text-sm text-ink-800">{{ corrective.action }}</p>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-xs text-ink-500">Due</dt>
                                <dd>{{ date(corrective.due_date) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-ink-500">Completed</dt>
                                <dd>{{ date(corrective.completed_on) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-ink-500">Effectiveness</dt>
                                <dd>{{ corrective.effectiveness ? titleCase(corrective.effectiveness) : '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-ink-500">Responsible</dt>
                                <dd>{{ corrective.responsible?.name ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div>
                        <h3 class="text-xs font-medium tracking-wide text-ink-500 uppercase">Preventive</h3>
                        <p v-if="preventive" class="mt-2 whitespace-pre-line text-sm text-ink-800">{{ preventive.action }}</p>
                        <p v-else class="mt-2 text-sm text-ink-500">None recorded.</p>
                    </div>
                </div>
            </Card>

            <Card title="Audit" subtitle="Creation, assignment, CAPA and every status change" class="lg:col-span-3" :padded="false">
                <ul v-if="audit.length" class="divide-y divide-slate-100 text-sm">
                    <li v-for="row in audit" :key="row.id" class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2">
                        <div>
                            <span class="font-medium text-ink-800">{{ titleCase(row.event) }}</span>
                            <span v-if="row.new_values?.status" class="ml-2 text-ink-600">
                                {{ row.old_values?.status }} → {{ row.new_values.status }}
                            </span>
                            <span v-else-if="row.new_values?.owner_id" class="ml-2 text-ink-600">owner changed</span>
                        </div>
                        <div class="text-xs text-ink-500">
                            {{ row.user?.name ?? 'system' }} · {{ datetime(row.created_at) }}
                        </div>
                    </li>
                </ul>
                <p v-else class="px-4 py-6 text-center text-sm text-ink-500">No audit rows yet.</p>
            </Card>
        </div>

        <Modal v-model:open="assignOpen" title="Assign this NCR" subtitle="Ownership is a person, not a status.">
            <FormField label="Owner" required :error="assignForm.errors.owner_id">
                <SelectInput
                    v-model="assignForm.owner_id"
                    :options="owners"
                    value-key="id"
                    label-key="name"
                    placeholder="Choose owner"
                />
            </FormField>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="assignForm.processing" :disabled="!assignForm.owner_id" @click="assign">
                    Assign
                </Button>
            </template>
        </Modal>

        <Modal
            v-model:open="investigateOpen"
            title="Record investigation"
            subtitle="Root cause and corrective action become the CAPA. Status moves to investigating."
            width="max-w-xl"
        >
            <div class="space-y-3">
                <FormField label="Root cause" required :error="investigateForm.errors.root_cause">
                    <textarea v-model="investigateForm.root_cause" rows="3" class="form-textarea" />
                </FormField>
                <FormField label="Corrective action" required :error="investigateForm.errors.action">
                    <textarea v-model="investigateForm.action" rows="3" class="form-textarea" />
                </FormField>
                <FormField label="Preventive action" :error="investigateForm.errors.preventive_action">
                    <textarea v-model="investigateForm.preventive_action" rows="2" class="form-textarea" />
                </FormField>
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Due date" :error="investigateForm.errors.due_date">
                        <DateInput v-model="investigateForm.due_date" />
                    </FormField>
                    <FormField label="Responsible" :error="investigateForm.errors.responsible_id">
                        <SelectInput
                            v-model="investigateForm.responsible_id"
                            :options="owners"
                            value-key="id"
                            label-key="name"
                            placeholder="Owner"
                        />
                    </FormField>
                </div>
            </div>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="investigateForm.processing" @click="investigate">
                    Record investigation
                </Button>
            </template>
        </Modal>

        <Modal v-model:open="verifyOpen" title="Effectiveness review" subtitle="QL-7: verification is a separate step from recording the action.">
            <FormField label="Effectiveness" required :error="verifyForm.errors.effectiveness">
                <SelectInput
                    v-model="verifyForm.effectiveness"
                    :options="[
                        { value: 'effective', label: 'Effective' },
                        { value: 'not_effective', label: 'Not effective' },
                    ]"
                    :placeholder="null"
                />
            </FormField>
            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="verifyForm.processing" @click="verify">Verify</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
