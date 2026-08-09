<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { datetime, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    artwork: { type: Object, required: true },
    versions: { type: Array, default: () => [] },
    nextVersionNo: { type: Number, required: true },
});

const approved = computed(() => props.versions.find((v) => v.status === 'approved') ?? null);
const selected = ref(props.versions[0] ?? null);

const uploadForm = useForm({ file: null });
const approveForm = useForm({ to: 'approved', customer_ref: '' });
const rejectForm = useForm({ to: 'rejected', rejection_reason: '' });

const approveOpen = ref(false);
const rejectOpen = ref(false);

function upload() {
    uploadForm.post(`/artworks/${props.artwork.id}/versions`, {
        forceFormData: true,
        onSuccess: () => uploadForm.reset(),
    });
}

function submitToCustomer(version) {
    router.post(`/artwork-versions/${version.id}/transition`, { to: 'submitted' }, { preserveScroll: true });
}

function approve() {
    approveForm.post(`/artwork-versions/${selected.value.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => {
            approveOpen.value = false;
            approveForm.reset();
        },
    });
}

function reject() {
    rejectForm.post(`/artwork-versions/${selected.value.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => {
            rejectOpen.value = false;
            rejectForm.reset();
        },
    });
}

function openApprove(version) {
    selected.value = version;
    approveForm.customer_ref = version.customer_ref ?? '';
    approveOpen.value = true;
}

function openReject(version) {
    selected.value = version;
    rejectOpen.value = true;
}
</script>

<template>
    <AppLayout>
        <Head :title="`${artwork.code} — artwork`" />

        <template #title>{{ artwork.code }} · {{ artwork.title }}</template>
        <template #subtitle>
            <Link v-if="artwork.product" :href="`/products/${artwork.product.id}`" class="hover:underline">
                {{ artwork.product.code }} — {{ artwork.product.name }}
            </Link>
            <span v-if="artwork.customer"> · {{ artwork.customer.name }}</span>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Gate 1 status -->
            <Card
                class="lg:col-span-3"
                title="Approval gate"
                rule="Gate 1 · A2"
                subtitle="At most one version may be approved at a time — the database enforces it, not the process"
            >
                <div
                    v-if="approved"
                    class="flex flex-wrap items-center gap-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2.5"
                >
                    <Badge tone="success" label="Approved" />
                    <span class="text-sm font-medium text-emerald-900">
                        Version {{ approved.version_no }} is the only version production may run against.
                    </span>
                    <span class="text-xs text-emerald-800">
                        Signed off {{ datetime(approved.approved_at) }} · evidence:
                        <span class="font-mono">{{ approved.customer_ref || '—' }}</span>
                    </span>
                </div>

                <div v-else class="flex flex-wrap items-center gap-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5">
                    <Badge tone="warning" label="No approved version" />
                    <span class="text-sm text-amber-900">
                        No job card can be released for this product. `job_cards.artwork_version_id` is
                        <code class="rounded bg-amber-100 px-1 font-mono text-xs">NOT NULL</code>
                        and must point at an approved version.
                    </span>
                </div>
            </Card>

            <!-- Version rail -->
            <Card class="lg:col-span-2" title="Versions" subtitle="Numbered contiguously from 1, never renumbered (A1)" :padded="false">
                <template #actions>
                    <form v-if="can('artwork.create')" class="flex items-center gap-2" @submit.prevent="upload">
                        <input
                            type="file"
                            class="text-xs file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-xs"
                            accept=".ai,.eps,.pdf,.cdr,.psd,.png,.jpg,.jpeg,.svg"
                            @input="uploadForm.file = $event.target.files[0]"
                        >
                        <Button type="submit" size="sm" variant="primary" :loading="uploadForm.processing" :disabled="!uploadForm.file">
                            Upload v{{ nextVersionNo }}
                        </Button>
                    </form>
                </template>

                <ul class="divide-y divide-slate-100">
                    <li v-for="version in versions" :key="version.id" class="p-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold text-slate-900">v{{ version.version_no }}</span>
                                    <Badge :status="version.status" />
                                    <Badge v-if="version.referenced_by_production" tone="info" label="In production" />
                                </div>

                                <p class="mt-1 font-mono text-[11px] break-all text-slate-500">
                                    {{ version.file_path }}
                                </p>

                                <!-- A3: the checksum is what proves the approved file is the file that went to plate-making -->
                                <p v-if="version.checksum_sha256" class="mt-0.5 font-mono text-[10px] break-all text-slate-400">
                                    sha256 {{ version.checksum_sha256 }}
                                </p>

                                <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-500">
                                    <div v-if="version.submitted_at">
                                        <dt class="inline">Submitted</dt>
                                        <dd class="inline font-medium text-slate-700">{{ datetime(version.submitted_at) }}</dd>
                                    </div>
                                    <div v-if="version.approved_at">
                                        <dt class="inline">Approved</dt>
                                        <dd class="inline font-medium text-slate-700">
                                            {{ datetime(version.approved_at) }} by {{ version.approved_by }}
                                        </dd>
                                    </div>
                                    <div v-if="version.customer_ref">
                                        <dt class="inline">Customer ref</dt>
                                        <dd class="inline font-medium text-slate-700">{{ version.customer_ref }}</dd>
                                    </div>
                                </dl>

                                <p v-if="version.rejection_reason" class="mt-2 rounded bg-rose-50 px-2 py-1 text-xs text-rose-800">
                                    {{ version.rejection_reason }}
                                </p>
                            </div>

                            <!-- Only what the state machine will actually allow -->
                            <div class="flex shrink-0 flex-wrap gap-1.5">
                                <Button
                                    v-if="version.available_transitions.includes('submitted')"
                                    size="sm"
                                    @click="submitToCustomer(version)"
                                >
                                    Submit to customer
                                </Button>
                                <Button
                                    v-if="version.available_transitions.includes('approved')"
                                    size="sm"
                                    variant="success"
                                    @click="openApprove(version)"
                                >
                                    Approve
                                </Button>
                                <Button
                                    v-if="version.available_transitions.includes('rejected')"
                                    size="sm"
                                    variant="danger"
                                    @click="openReject(version)"
                                >
                                    Reject
                                </Button>
                            </div>
                        </div>
                    </li>

                    <li v-if="versions.length === 0" class="p-6 text-center text-sm text-slate-500">
                        No versions yet. Upload version 1 to begin.
                    </li>
                </ul>
            </Card>

            <Card title="Artwork">
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-xs text-slate-500">Code</dt>
                        <dd class="font-medium text-slate-900">{{ artwork.code }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">Title</dt>
                        <dd class="text-slate-800">{{ artwork.title }}</dd>
                    </div>
                    <div v-if="artwork.designer">
                        <dt class="text-xs text-slate-500">Designer</dt>
                        <dd class="text-slate-800">{{ artwork.designer }}</dd>
                    </div>
                    <div v-if="artwork.product">
                        <dt class="text-xs text-slate-500">Product type</dt>
                        <dd class="text-slate-800">{{ titleCase(artwork.product.product_type) }}</dd>
                    </div>
                </dl>
            </Card>
        </div>

        <!-- Approval requires evidence: an approval with no customer reference is a claim, not a record -->
        <Modal
            v-model:open="approveOpen"
            title="Approve this version"
            subtitle="Approving supersedes the current approved version in the same transaction (A2)."
        >
            <FormField
                label="Customer reference"
                rule="A2"
                hint="The email, sample tag or portal sign-off that evidences the approval. Required."
                :error="approveForm.errors.customer_ref"
                required
            >
                <TextInput v-model="approveForm.customer_ref" placeholder="e.g. email 2026-08-09 from Nadia at H&M" />
            </FormField>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="success" :loading="approveForm.processing" :disabled="!approveForm.customer_ref" @click="approve">
                    Approve v{{ selected?.version_no }}
                </Button>
            </template>
        </Modal>

        <Modal v-model:open="rejectOpen" title="Reject this version" subtitle="The reason goes back to the studio queue.">
            <FormField label="Rejection reason" :error="rejectForm.errors.rejection_reason" required>
                <textarea v-model="rejectForm.rejection_reason" rows="3" class="form-textarea" />
            </FormField>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="danger" :loading="rejectForm.processing" :disabled="!rejectForm.rejection_reason" @click="reject">
                    Reject v{{ selected?.version_no }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
