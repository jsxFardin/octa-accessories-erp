<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { datetime, number, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useGuardedAction } from '@/composables/useGuardedAction';

const props = defineProps({
    artwork: { type: Object, required: true },
    versions: { type: Array, default: () => [] },
    nextVersionNo: { type: Number, required: true },
    designers: { type: Array, default: () => [] },
    /** True once a version exists: the code is a reference other people already hold. */
    codeLocked: { type: Boolean, default: false },
});

const approved = computed(() => props.versions.find((v) => v.status === 'approved') ?? null);

/**
 * Gate 1 in the reader's terms. The blocker is real — a job card cannot exist without an
 * approved version — but the person looking at this screen needs the next move, not the
 * constraint that stopped them. A rejected version is terminal, so it asks for a new one.
 */
const blockedNextStep = computed(() => {
    const waiting = props.versions.find((v) => v.status === 'submitted');

    if (waiting) {
        return `Version ${waiting.version_no} is with the customer. Record their sign-off here once it arrives.`;
    }

    const drafted = props.versions.find((v) => v.status === 'draft');

    if (drafted) {
        return `Send version ${drafted.version_no} to the customer, then record their approval here.`;
    }

    if (props.versions.length === 0) {
        return 'Upload the first version to start the approval trail.';
    }

    return 'The last version was rejected. Upload a corrected version and send it to the customer.';
});

const selected = ref(props.versions[0] ?? null);

/*
 * Gate 1 asks someone to approve a design, so the design has to be on the screen. The file is
 * private — it is the customer's intellectual property and never had a public URL — so it is
 * streamed through `/artwork-versions/{id}/file`, which carries the same permission as this
 * page.
 *
 * A browser can draw a PNG, a JPEG, an SVG and a PDF. It cannot draw an AI, EPS, PSD or CDR,
 * and those are the formats a studio actually works in — so those offer the file instead of
 * pretending to render it.
 */
const IMAGE_FORMATS = ['png', 'jpg', 'svg'];

function fileUrl(version) {
    return `/artwork-versions/${version.id}/file`;
}

/** The name the file downloads as: the artwork code, the version and the format. */
function fileName(version) {
    return `${props.artwork.code}-v${version.version_no}${version.file_format ? `.${version.file_format}` : ''}`;
}

function downloadUrl(version) {
    return `${fileUrl(version)}?download=1`;
}

function isImage(version) {
    return IMAGE_FORMATS.includes(version.file_format);
}

function isPdf(version) {
    return version.file_format === 'pdf';
}

function isRenderable(version) {
    return isImage(version) || isPdf(version);
}

const previewOpen = ref(false);
const previewed = ref(null);

function openPreview(version) {
    previewed.value = version;
    previewOpen.value = true;
}

const uploadForm = useForm({ file: null });
const approveForm = useForm({ to: 'approved', customer_ref: '' });
const rejectForm = useForm({ to: 'rejected', rejection_reason: '' });

const approveOpen = ref(false);
const rejectOpen = ref(false);
const editOpen = ref(false);

const editForm = useForm({
    code: props.artwork.code,
    title: props.artwork.title,
    designer_id: props.artwork.designer_id ?? '',
});

function saveArtwork() {
    editForm.put(`/artworks/${props.artwork.id}`, {
        preserveScroll: true,
        onSuccess: () => (editOpen.value = false),
    });
}

/*
 * The limits the server enforces, said before the upload rather than after it. A 60 MB file
 * used to be sent in full, with no sign of progress, and then refused in a toast.
 */
const MAX_UPLOAD_MB = 50;
const UPLOAD_FORMATS = ['ai', 'eps', 'pdf', 'cdr', 'psd', 'png', 'jpg', 'jpeg', 'svg'];

const fileInput = ref(null);

/** Why the chosen file cannot be sent, known without sending it. */
const fileProblem = computed(() => {
    const file = uploadForm.file;

    if (!file) return null;

    const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';

    if (!UPLOAD_FORMATS.includes(extension)) {
        return `".${extension || 'no extension'}" files cannot be uploaded. Use AI, EPS, PDF, CDR, PSD, PNG, JPG or SVG.`;
    }

    if (file.size > MAX_UPLOAD_MB * 1024 * 1024) {
        return `This file is ${megabytes(file.size)} MB. The limit is ${MAX_UPLOAD_MB} MB; export a smaller copy and upload that.`;
    }

    return null;
});

function megabytes(bytes) {
    return number(bytes / 1024 / 1024, bytes < 10 * 1024 * 1024 ? 1 : 0);
}

function chooseFile(event) {
    uploadForm.clearErrors();
    uploadForm.file = event.target.files[0] ?? null;
}

function upload() {
    if (!uploadForm.file || fileProblem.value) return;

    uploadForm.post(`/artworks/${props.artwork.id}/versions`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

const { busy, run } = useGuardedAction();

function submitToCustomer(version) {
    run(`submit-${version.id}`, {
        title: `Mark version ${version.version_no} as submitted to the customer?`,
        message: 'The version is recorded as sent for approval and can no longer be replaced. Nothing is emailed from here — send the file to the customer yourself.',
        confirmLabel: 'Mark as submitted',
    }, (done) => router.post(`/artwork-versions/${version.id}/transition`, { to: 'submitted' }, { preserveScroll: true, ...done }));
}

function withdraw(version) {
    run(`withdraw-${version.id}`, {
        title: `Withdraw version ${version.version_no}?`,
        message: 'The file is removed as if it had never been uploaded, and the next upload takes its number. This cannot be undone.',
        confirmLabel: 'Withdraw version',
        tone: 'danger',
    }, (done) => router.delete(`/artwork-versions/${version.id}`, { preserveScroll: true, ...done }));
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
            <Link v-if="artwork.product" :href="`/products/${artwork.product.id}`" class="doc-link">
                {{ artwork.product.code }} — {{ artwork.product.name }}
            </Link>
            <span v-if="artwork.customer"> · {{ artwork.customer.name }}</span>
        </template>

        <template #actions>
            <Button v-if="can('artwork.update')" size="sm" @click="editOpen = true">Edit details</Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Gate 1 status -->
            <Card
                class="lg:col-span-3"
                title="Approval gate"
                rule="Gate 1 · A2"
                subtitle="Only one version can be approved at a time. Approving another replaces it."
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
                        Production is blocked: no job card can be released for this artwork until one version
                        is approved. {{ blockedNextStep }}
                    </span>
                </div>
            </Card>

            <!-- Version rail -->
            <Card class="lg:col-span-2" title="Versions" subtitle="Numbered from 1 in the order they were uploaded" :padded="false">
                <!--
                    The upload sits in the card, not squeezed into its title bar, because it now
                    says what may be uploaded, how far it has got, and why it was refused.
                -->
                <form
                    v-if="can('artwork.create')"
                    class="border-b border-slate-200 px-3 py-3"
                    data-upload
                    @submit.prevent="upload"
                >
                    <div class="flex flex-wrap items-end gap-3">
                        <FormField
                            :label="`Upload version ${nextVersionNo}`"
                            :hint="`AI, EPS, PDF, CDR, PSD, PNG, JPG or SVG, up to ${MAX_UPLOAD_MB} MB.`"
                            :error="uploadForm.errors.file ?? fileProblem"
                            class="min-w-0 flex-1"
                        >
                            <input
                                ref="fileInput"
                                type="file"
                                class="block w-full text-sm file:mr-3 file:min-h-9 file:cursor-pointer file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-ink-700 hover:file:bg-slate-200"
                                accept=".ai,.eps,.pdf,.cdr,.psd,.png,.jpg,.jpeg,.svg"
                                :disabled="uploadForm.processing"
                                :aria-invalid="uploadForm.errors.file || fileProblem ? 'true' : 'false'"
                                @change="chooseFile"
                            >
                        </FormField>
                        <Button
                            type="submit"
                            size="md"
                            variant="primary"
                            :loading="uploadForm.processing"
                            :disabled="!uploadForm.file || fileProblem !== null || uploadForm.processing"
                        >
                            Upload
                        </Button>
                    </div>

                    <!-- A large file takes a while on a factory line; say how far it has got. -->
                    <div v-if="uploadForm.processing" class="mt-3" role="status" aria-live="polite">
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-brand-600 transition-[width]" :style="{ width: `${uploadForm.progress?.percentage ?? 0}%` }" />
                        </div>
                        <p class="mt-1 text-xs text-ink-600">
                            <template v-if="(uploadForm.progress?.percentage ?? 0) < 100">
                                Uploading {{ uploadForm.file?.name }}: {{ uploadForm.progress?.percentage ?? 0 }}%
                            </template>
                            <template v-else>Uploaded. Checking the file…</template>
                        </p>
                    </div>
                </form>

                <ul class="divide-y divide-slate-100">
                    <li v-for="version in versions" :key="version.id" class="p-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <!-- The design itself, at a size that says which one this is. -->
                            <button
                                v-if="isRenderable(version)"
                                type="button"
                                class="size-16 shrink-0 overflow-hidden rounded border border-slate-200 bg-slate-50 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                :aria-label="`Preview version ${version.version_no}`"
                                @click="openPreview(version)"
                            >
                                <img
                                    v-if="isImage(version)"
                                    :src="fileUrl(version)"
                                    :alt="`Artwork version ${version.version_no}`"
                                    class="size-full object-contain"
                                    loading="lazy"
                                >
                                <span v-else class="flex size-full items-center justify-center text-xs font-medium text-ink-500">
                                    PDF
                                </span>
                            </button>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold text-ink-900">v{{ version.version_no }}</span>
                                    <Badge :status="version.status" />
                                    <Badge v-if="version.referenced_by_production" tone="info" label="In production" />
                                </div>

                                <p class="mt-1 text-xs text-ink-600">
                                    {{ fileName(version) }}
                                </p>

                                <!--
                                    Where the file is kept and its fingerprint are for whoever has
                                    to prove the approved file is the one that went to plate-making.
                                    They used to be printed in full on every version.
                                -->
                                <details v-if="version.checksum_sha256" class="mt-0.5 text-xs text-ink-600">
                                    <summary class="cursor-pointer select-none hover:text-ink-900">File details</summary>
                                    <p class="mt-1 break-all">Stored as {{ version.file_path }}</p>
                                    <p class="break-all">Fingerprint (SHA-256): <span class="font-mono">{{ version.checksum_sha256 }}</span></p>
                                </details>

                                <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-500">
                                    <div v-if="version.submitted_at">
                                        <dt class="inline">Submitted</dt>
                                        <dd class="inline font-medium text-ink-700">{{ datetime(version.submitted_at) }}</dd>
                                    </div>
                                    <div v-if="version.approved_at">
                                        <dt class="inline">Approved</dt>
                                        <dd class="inline font-medium text-ink-700">
                                            {{ datetime(version.approved_at) }} by {{ version.approved_by }}
                                        </dd>
                                    </div>
                                    <div v-if="version.customer_ref">
                                        <dt class="inline">Customer ref</dt>
                                        <dd class="inline font-medium text-ink-700">{{ version.customer_ref }}</dd>
                                    </div>
                                </dl>

                                <p v-if="version.rejection_reason" class="mt-2 rounded bg-rose-50 px-2 py-1 text-xs text-rose-800">
                                    {{ version.rejection_reason }}
                                </p>
                            </div>

                            <!-- Only what the state machine will actually allow -->
                            <div class="flex shrink-0 flex-wrap gap-1.5">
                                <Button v-if="isRenderable(version)" size="sm" @click="openPreview(version)">
                                    Preview
                                </Button>
                                <!-- external: the file leaves the SPA, and an Inertia visit would
                                     fetch bytes it cannot mount. -->
                                <Button size="sm" external :href="downloadUrl(version)">Download</Button>
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
                                <!-- Only a draft nobody outside this screen has seen. -->
                                <Button
                                    v-if="version.can_withdraw && can('artwork.create')"
                                    size="sm"
                                    variant="ghost"
                                    :loading="busy === `withdraw-${version.id}`"
                                    :disabled="busy !== null"
                                    data-withdraw
                                    @click="withdraw(version)"
                                >
                                    Withdraw
                                </Button>
                            </div>
                        </div>
                    </li>

                    <li v-if="versions.length === 0" class="p-6 text-center text-sm text-ink-500">
                        No versions yet. Upload version 1 above to begin.
                    </li>
                </ul>
            </Card>

            <Card title="Artwork">
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-xs text-ink-500">Code</dt>
                        <dd class="font-medium text-ink-900">{{ artwork.code }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Title</dt>
                        <dd class="text-ink-800">{{ artwork.title }}</dd>
                    </div>
                    <div v-if="artwork.designer">
                        <dt class="text-xs text-ink-500">Designer</dt>
                        <dd class="text-ink-800">{{ artwork.designer }}</dd>
                    </div>
                    <div v-if="artwork.product">
                        <dt class="text-xs text-ink-500">Product type</dt>
                        <dd class="text-ink-800">{{ titleCase(artwork.product.product_type) }}</dd>
                    </div>
                </dl>
            </Card>
        </div>

        <!-- Approval requires evidence: an approval with no customer reference is a claim, not a record -->
        <Modal
            v-model:open="approveOpen"
            title="Approve this version"
            subtitle="Approving this version replaces the one approved now."
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
        <Modal
            v-model:open="editOpen"
            title="Edit artwork"
            subtitle="Uploaded versions cannot be changed. Only the code, title and designer are edited here."
        >
            <form class="space-y-3" @submit.prevent="saveArtwork">
                <FormField
                    label="Code"
                    :hint="codeLocked ? 'Locked — versions already reference this code.' : null"
                    :error="editForm.errors.code"
                >
                    <TextInput v-model="editForm.code" :disabled="codeLocked" />
                </FormField>

                <FormField label="Title" :error="editForm.errors.title" required>
                    <TextInput v-model="editForm.title" />
                </FormField>

                <FormField label="Designer" :error="editForm.errors.designer_id">
                    <SelectInput v-model="editForm.designer_id" :options="designers" value-key="id" label-key="name" />
                </FormField>
            </form>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="editForm.processing" :disabled="!editForm.title" @click="saveArtwork">
                    Save
                </Button>
            </template>
        </Modal>
        <!-- The design, full size. A PDF gets a frame because a browser draws one natively;
             everything a browser cannot draw is offered as a file instead. -->
        <Modal
            v-model:open="previewOpen"
            width="max-w-5xl"
            :title="previewed ? `Version ${previewed.version_no}` : 'Preview'"
            :subtitle="artwork.code + ' · ' + artwork.title"
        >
            <div v-if="previewed" class="space-y-2">
                <div class="flex items-center justify-center rounded-md border border-slate-200 bg-slate-50 p-2">
                    <img
                        v-if="isImage(previewed)"
                        :src="fileUrl(previewed)"
                        :alt="`Artwork version ${previewed.version_no}`"
                        class="max-h-[70vh] w-auto max-w-full object-contain"
                    >
                    <iframe
                        v-else-if="isPdf(previewed)"
                        :src="fileUrl(previewed)"
                        class="h-[70vh] w-full rounded bg-white"
                        :title="`Artwork version ${previewed.version_no}`"
                    />
                </div>

                <p v-if="previewed.checksum_sha256" class="font-mono text-xs break-all text-ink-400">
                    Fingerprint (SHA-256): {{ previewed.checksum_sha256 }}
                </p>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Close</Button>
                <Button v-if="previewed" variant="primary" external :href="downloadUrl(previewed)">Download file</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
