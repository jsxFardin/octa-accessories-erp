<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SlideOver from '@/Components/Ui/SlideOver.vue';

/**
 * One import panel for every master-data list, paired with ExportDialog.
 *
 * Three things it refuses to do, each of them learned from an import that went wrong:
 *
 *  - **Guess the columns.** The field table and the sample file both come from the server's
 *    ImportRegistry, so what this panel documents is what the parser accepts.
 *  - **Hide the failures.** A file with eleven bad rows out of four hundred imports 389 and
 *    names the eleven with their row numbers. "Import failed" sends somebody hunting blind.
 *  - **Leave a stale list behind.** A successful import reloads the page underneath, because
 *    a list that still shows the old eighty rows reads as an import that did nothing.
 *  - **Import on sight.** Choosing a file only checks it. The server runs the real import and
 *    takes it back, so the counts shown are the counts that will happen; nothing is written
 *    until the person presses Import.
 */
const props = defineProps({
    /** Key from the server's ImportRegistry, e.g. `customers`. */
    resource: { type: String, required: true },
    /** What the list is called, for the panel title. */
    label: { type: String, required: true },
});

const open = ref(false);
const guidelines = ref(false);
const uploading = ref(false);
const dragging = ref(false);
const spec = ref(null);
const result = ref(null);
const failure = ref(null);
const fileInput = ref(null);
/** The file that has been checked and is waiting for a decision, and what the check found. */
const pending = ref(null);
const preview = ref(null);
const checking = ref(false);
/** The name of the file the last import took, for the report after it. */
const importedName = ref('');

const accept = computed(() => (spec.value?.extensions ?? ['csv', 'xlsx']).map((e) => `.${e}`).join(','));
const imported = computed(() => (result.value?.created ?? 0) + (result.value?.updated ?? 0));
const wouldImport = computed(() => (preview.value?.created ?? 0) + (preview.value?.updated ?? 0));
/** What is on screen: the check's findings before the import, the import's own counts after. */
const report = computed(() => result.value ?? preview.value);
const rows = (count) => `${Number(count).toLocaleString()} ${count === 1 ? 'row' : 'rows'}`;

async function show() {
    open.value = true;
    reset();

    if (spec.value) return;

    const response = await fetch(`/imports/${props.resource}/fields`, {
        headers: { Accept: 'application/json' },
    });

    if (response.ok) spec.value = await response.json();
}

function reset() {
    result.value = null;
    failure.value = null;
    pending.value = null;
    preview.value = null;
}

function pick(event) {
    const file = event.target.files?.[0];

    if (file) check(file);
    event.target.value = '';
}

function drop(event) {
    dragging.value = false;

    const file = event.dataTransfer?.files?.[0];

    if (file && !uploading.value && !checking.value) check(file);
}

async function send(file, checkOnly) {
    const body = new FormData();

    body.append('file', file);
    if (checkOnly) body.append('check', '1');

    const response = await fetch(`/imports/${props.resource}`, {
        method: 'POST',
        body,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        // 422 from the validator arrives as `errors`, from an abort() as `message`.
        throw new Error(payload.errors?.file?.[0] ?? payload.message ?? 'That file could not be read.');
    }

    return payload;
}

/** Step one: read the file and say what importing it would do. Nothing is written. */
async function check(file) {
    reset();
    checking.value = true;

    try {
        preview.value = await send(file, true);
        pending.value = file;
    } catch (error) {
        failure.value = error instanceof TypeError
            ? 'The file did not reach the server. Check the connection and try again.'
            : error.message;
    } finally {
        checking.value = false;
    }
}

/** Step two, only when asked: the same file, for real. */
async function confirmImport() {
    if (!pending.value || uploading.value) return;

    uploading.value = true;
    failure.value = null;

    try {
        result.value = await send(pending.value, false);
        importedName.value = pending.value.name;
        pending.value = null;
        preview.value = null;

        // Only when something actually landed: reloading after a file of four hundred
        // rejected rows just hides the report the person needs to read.
        if (imported.value > 0) router.reload({ preserveScroll: true });
    } catch (error) {
        failure.value = error instanceof TypeError
            ? 'The import did not reach the server, so nothing was changed. Check the connection and try again.'
            : error.message;
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <Button size="sm" @click="show">
        <Icon name="upload" size="size-3.5" />
        Import
    </Button>

    <SlideOver
        v-model:open="open"
        width="max-w-2xl"
        :title="`Import ${label.toLowerCase()}`"
        subtitle="A spreadsheet of rows. Existing records are updated, new ones created. The file is checked first; nothing changes until you press Import."
        :dirty="pending !== null"
    >
        <div class="space-y-4">
            <div class="flex items-center justify-between gap-2">
                <a
                    class="text-xs font-medium text-brand-700 hover:underline"
                    :href="`/imports/${resource}/sample`"
                >
                    Download sample file
                </a>

                <button
                    class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-ink-700 ring-1 ring-slate-300 transition hover:bg-slate-50"
                    @click="guidelines = true"
                >
                    <Icon name="info" size="size-3.5" />
                    Import guidelines
                </button>
            </div>

            <!-- The drop zone doubles as the file picker: dragging is faster for the people
                 who have the file open, clicking is the only route for everyone else. -->
            <div
                class="rounded-lg border-2 border-dashed px-6 py-10 text-center transition"
                :class="dragging ? 'border-brand-500 bg-brand-50' : 'border-slate-300 bg-slate-50/60'"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="drop"
            >
                <Icon name="upload" size="size-7" class="mx-auto text-brand-500" />

                <p class="mt-2 text-sm font-medium text-ink-800">
                    {{ uploading ? 'Importing…' : checking ? 'Checking the file…' : pending ? 'Checked. Choose another file to check that one instead.' : 'Drop a file here, or choose one' }}
                </p>
                <p class="mt-0.5 text-xs text-ink-500">
                    Up to {{ spec?.maxSize ?? '10MB' }} and {{ (spec?.maxRows ?? 1000).toLocaleString() }} rows ·
                    {{ (spec?.extensions ?? ['csv', 'xlsx']).map((e) => `.${e}`).join(' ') }}
                </p>

                <input ref="fileInput" type="file" class="hidden" :accept="accept" @change="pick">

                <Button class="mt-3" size="sm" :loading="checking" :disabled="checking || uploading" @click="fileInput.click()">
                    {{ pending || result ? 'Choose another file' : 'Choose file' }}
                </Button>
            </div>

            <div v-if="failure" class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200">
                {{ failure }}
            </div>

            <div v-if="report" class="space-y-3" data-import-report>
                <!-- Before: what will happen. After: what did. The same three boxes, said differently. -->
                <p class="text-sm font-medium text-ink-900" role="status">
                    <template v-if="result">"{{ importedName }}" has been imported.</template>
                    <template v-else>
                        "{{ pending?.name }}" has been checked. Nothing has been changed yet.
                    </template>
                </p>

                <div class="grid grid-cols-3 gap-2">
                    <div class="rounded-md bg-emerald-50 px-3 py-2 ring-1 ring-emerald-200">
                        <p class="text-lg font-semibold text-emerald-800">{{ report.created }}</p>
                        <p class="text-xs text-emerald-800">{{ result ? 'Created' : 'Will be created' }}</p>
                    </div>
                    <div class="rounded-md bg-brand-50 px-3 py-2 ring-1 ring-brand-200">
                        <p class="text-lg font-semibold text-brand-800">{{ report.updated }}</p>
                        <p class="text-xs text-brand-800">{{ result ? 'Updated' : 'Will be updated' }}</p>
                    </div>
                    <div
                        class="rounded-md px-3 py-2 ring-1"
                        :class="report.skipped ? 'bg-amber-50 ring-amber-200' : 'bg-slate-50 ring-slate-200'"
                    >
                        <p class="text-lg font-semibold" :class="report.skipped ? 'text-amber-800' : 'text-ink-700'">
                            {{ report.skipped }}
                        </p>
                        <p class="text-xs" :class="report.skipped ? 'text-amber-800' : 'text-ink-600'">
                            {{ result ? 'Skipped' : 'Will be skipped' }}
                        </p>
                    </div>
                </div>

                <div v-if="report.errors?.length" class="rounded-md ring-1 ring-slate-200">
                    <p class="border-b border-slate-200 px-3 py-2 text-xs font-medium text-ink-700">
                        <template v-if="result">Rows that were skipped. Fix these in the file and import it again.</template>
                        <template v-else>
                            Rows that will be skipped. Fix them in the file and choose it again, or import the rest now.
                        </template>
                        <span v-if="report.skipped > report.errors.length" class="font-normal text-ink-600">
                            Showing the first {{ report.errors.length }} of {{ report.skipped }}.
                        </span>
                    </p>

                    <ul class="max-h-64 divide-y divide-slate-100 overflow-y-auto">
                        <li v-for="error in report.errors" :key="error.row" class="px-3 py-2 text-xs">
                            <span class="font-medium text-ink-900">Row {{ error.row }}</span>
                            <ul class="mt-0.5 space-y-0.5 text-ink-600">
                                <li v-for="(message, index) in error.messages" :key="index">{{ message }}</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <p v-else-if="report.rows === 0" class="text-xs text-ink-600">
                    That file has no data rows in it.
                </p>

                <p v-if="!result && wouldImport === 0 && report.rows > 0" class="text-sm text-amber-800">
                    Every row in this file would be skipped, so there is nothing to import.
                </p>
            </div>
        </div>

        <template #footer="{ close }">
            <Button @click="close">{{ result ? 'Done' : 'Cancel' }}</Button>
            <!-- The only thing that writes. Says how much, because that is the decision. -->
            <Button
                v-if="pending && wouldImport > 0"
                variant="primary"
                :loading="uploading"
                :disabled="uploading"
                data-confirm-import
                @click="confirmImport"
            >
                Import {{ rows(wouldImport) }}
            </Button>
        </template>
    </SlideOver>

    <Modal
        v-model:open="guidelines"
        width="max-w-3xl"
        :title="`${label} import guidelines`"
        subtitle="Supported fields, formats and what happens to a row that fails."
    >
        <div class="space-y-4">
            <div class="rounded-md bg-slate-50 px-3 py-2.5 ring-1 ring-slate-200">
                <p class="field-label mb-1">General</p>
                <ul class="list-disc space-y-1 pl-4 text-xs text-ink-700">
                    <li>The first row is the header. Column order does not matter, and columns nobody asked for are ignored.</li>
                    <li>Up to {{ (spec?.maxRows ?? 1000).toLocaleString() }} rows and {{ spec?.maxSize ?? '10MB' }} per file.</li>
                    <li>Formats: {{ (spec?.extensions ?? []).map((e) => `.${e}`).join(', ') }}.</li>
                    <li>Dates as YYYY-MM-DD. Amounts as plain numbers — no currency symbols.</li>
                    <li>Yes/no columns accept yes, no, true, false, 1 or 0.</li>
                    <li>
                        A row whose <code class="rounded bg-white px-1 ring-1 ring-slate-200">{{ spec?.key ?? 'code' }}</code>
                        already exists updates that record; the rest are created.
                    </li>
                    <li>Rows that fail validation are skipped and listed back with their row numbers; the rest import.</li>
                </ul>
            </div>

            <div class="overflow-hidden rounded-md ring-1 ring-slate-200">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-[11px] tracking-wider text-ink-500 uppercase">
                        <tr>
                            <th class="px-3 py-2 font-medium">Field</th>
                            <th class="px-3 py-2 font-medium">Type</th>
                            <th class="px-3 py-2 font-medium">Example</th>
                            <th class="px-3 py-2 font-medium">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="field in spec?.fields ?? []" :key="field.name">
                            <td class="px-3 py-2 align-top">
                                <code class="text-ink-900">{{ field.name }}</code>
                                <span v-if="field.required" class="ml-1 text-[10px] font-medium text-rose-600">Required</span>
                            </td>
                            <td class="px-3 py-2 align-top">
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-ink-600">{{ field.type }}</span>
                            </td>
                            <td class="px-3 py-2 align-top">
                                <code class="text-ink-600">{{ field.example }}</code>
                            </td>
                            <td class="px-3 py-2 align-top text-ink-600">
                                {{ field.description }}
                                <span v-if="field.options?.length" class="block text-ink-500">
                                    One of: {{ field.options.join(', ') }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <template #footer="{ close }">
            <Button @click="close">Close</Button>
        </template>
    </Modal>
</template>
